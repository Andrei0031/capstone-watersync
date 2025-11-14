from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
from typing import List, Optional
from datetime import datetime
import numpy as np

try:
    from lightgbm import LGBMRegressor
    has_lgbm = True
except Exception:
    has_lgbm = False

try:
    from sklearn.linear_model import LinearRegression
    from sklearn.exceptions import NotFittedError
except Exception:
    LinearRegression = None
    NotFittedError = Exception

app = FastAPI(title="Revenue Forecast Service", version="1.0.0")


class HistoryPoint(BaseModel):
    period: str  # 'YYYY-MM' or 'YYYY'
    revenue: float


class ForecastRequest(BaseModel):
    history: List[HistoryPoint]
    horizon: int = 6


def parse_period_to_month_index(period: str) -> int:
    # Supports 'YYYY-MM' and 'YYYY'
    if '-' in period:
        year, month = period.split('-')
        return int(year) * 12 + int(month)
    else:
        return int(period) * 12 + 1


def month_index_to_period(idx: int) -> str:
    year = idx // 12
    month = idx % 12
    if month == 0:
        year -= 1
        month = 12
    return f"{year}-{month:02d}"


def build_features(month_indices: np.ndarray, revenues: np.ndarray):
    # month number in year
    months = (month_indices % 12)
    months[months == 0] = 12
    # simple time index for trend
    t = np.arange(1, len(revenues) + 1)

    # lags
    def lag(arr, k):
        out = np.full_like(arr, np.nan, dtype=float)
        out[k:] = arr[:-k]
        return out

    lag1 = lag(revenues, 1)
    lag2 = lag(revenues, 2)
    lag3 = lag(revenues, 3)
    lag12 = lag(revenues, 12)

    # rolling means
    def roll_mean(arr, w):
        out = np.full_like(arr, np.nan, dtype=float)
        for i in range(w - 1, len(arr)):
            out[i] = np.nanmean(arr[i - w + 1 : i + 1])
        return out

    rm3 = roll_mean(revenues, 3)
    rm6 = roll_mean(revenues, 6)
    rm12 = roll_mean(revenues, 12)

    X = np.vstack([months, t, lag1, lag2, lag3, lag12, rm3, rm6, rm12]).T
    return X


@app.post("/forecast")
def forecast(req: ForecastRequest):
    hist = req.history or []
    horizon = max(1, min(24, req.horizon or 6))
    if len(hist) < 6:
        raise HTTPException(status_code=400, detail="Not enough history; need at least 6 points")

    # Sort by period
    hist_sorted = sorted(hist, key=lambda x: parse_period_to_month_index(x.period))
    months_idx = np.array([parse_period_to_month_index(h.period) for h in hist_sorted], dtype=int)
    y = np.array([float(h.revenue) for h in hist_sorted], dtype=float)

    X = build_features(months_idx, y)

    # Drop rows with NaNs (due to lags/rolling)
    mask = ~np.isnan(X).any(axis=1)
    X_train = X[mask]
    y_train = y[mask]
    months_train = months_idx[mask]

    if len(y_train) < 4:
        # fallback to simple linear trend on time
        if LinearRegression is None:
            raise HTTPException(status_code=500, detail="LinearRegression not available")
        lr = LinearRegression()
        t = np.arange(1, len(y) + 1).reshape(-1, 1)
        lr.fit(t, y)
        last_idx = months_idx[-1]
        forecasts = []
        for i in range(1, horizon + 1):
            t_future = np.array([[len(y) + i]])
            val = float(max(0.0, lr.predict(t_future)[0]))
            forecasts.append({
                'period': month_index_to_period(last_idx + i),
                'revenue': val
            })
        return { 'forecast': forecasts }

    # Fit LightGBM if present, else linear regression on features
    model = None
    if has_lgbm:
        model = LGBMRegressor(
            n_estimators=400,
            learning_rate=0.05,
            max_depth=-1,
            subsample=0.9,
            colsample_bytree=0.9,
            reg_alpha=0.1,
            reg_lambda=0.5,
            random_state=42
        )
    elif LinearRegression is not None:
        model = LinearRegression()
    else:
        raise HTTPException(status_code=500, detail="No ML backend available")

    model.fit(X_train, y_train)

    # Iterative forecasting to roll lags forward
    last_month_idx = months_idx[-1]
    y_all = y.copy()
    idx_all = months_idx.copy()
    forecasts = []
    for i in range(1, horizon + 1):
        next_idx = last_month_idx + i
        idx_all = np.append(idx_all, next_idx)
        # Rebuild features with the extended y_all
        X_all = build_features(idx_all, y_all)
        x_next = X_all[-1]
        # If NaNs present (too short), rebuild with mask
        if np.isnan(x_next).any():
            # fall back to last valid window
            X_tmp = X_all[~np.isnan(X_all).any(axis=1)]
            if len(X_tmp) == 0:
                pred_val = float(y_all[-1])
            else:
                pred_val = float(model.predict([X_tmp[-1]])[0])
        else:
            pred_val = float(model.predict([x_next])[0])
        pred_val = max(0.0, pred_val)
        y_all = np.append(y_all, pred_val)
        forecasts.append({ 'period': month_index_to_period(next_idx), 'revenue': pred_val })

    return { 'forecast': forecasts }



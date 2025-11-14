# How to Get Your Roboflow Model ID

## From "Hosted Image Inference" Page

When you click **"Hosted Image Inference"** in Roboflow, you'll see Python code like this:

```python
from inference_sdk import InferenceHTTPClient

CLIENT = InferenceHTTPClient(
    api_url="https://serverless.roboflow.com",
    api_key="plVsmWuM0KjEA8Pz6RqB"
)

result = CLIENT.infer("YOUR_IMAGE.jpg", model_id="watersync-oekrf/2")
```

## Extract the Model ID

From the code above, find this line:
```python
model_id="watersync-oekrf/2"
```

The **model_id** is: `watersync-oekrf/2`

This format is: `project-name/version-number`

## Update Your Code

1. Open `C:\xampp\htdocs\CAPSTONE\api\roboflow_service.php`

2. Find line 20 (around there):
   ```php
   define('ROBOFLOW_DIGIT_MODEL_ID', 'watersync-digits/1');
   ```

3. Replace `'watersync-digits/1'` with your actual model_id from Roboflow

   **Example:**
   - If Roboflow shows: `model_id="watersync-digits/2"`
   - Update to: `define('ROBOFLOW_DIGIT_MODEL_ID', 'watersync-digits/2');`

## Alternative: Get from URL

If you see a URL like:
```
https://detect.roboflow.com/watersync/watersync-digits/1?api_key=...
```

The model_id would be: `watersync-digits/1` (project/version part)

## Important Notes

- **Model ID format**: `project-name/version-number` (no workspace prefix)
- **Version number**: Usually `1`, `2`, `3`, etc. (the version you trained)
- **Project name**: Your digit detection project name (e.g., `watersync-digits`)

## Example

If your Roboflow code shows:
```python
model_id="my-digits-project/3"
```

Then update your PHP code to:
```php
define('ROBOFLOW_DIGIT_MODEL_ID', 'my-digits-project/3');
```

That's it! The code will automatically use this model_id to build the correct API endpoint.


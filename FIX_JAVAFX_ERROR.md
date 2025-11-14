# Fix JavaFX Error - Solutions

## Error: `java.lang.NoClassDefFoundError: javafx/application/Application`

This means **JavaFX is not available** in your Java installation.

## ✅ Solution 1: Use Older jTessBoxEditor (Easiest - Recommended)

**Download the non-FX version** (doesn't need JavaFX):

1. **Go to:** https://sourceforge.net/projects/vietocr/files/jTessBoxEditor/
2. **Download:** `jTessBoxEditor-2.3.1.jar` (older version, no JavaFX needed)
3. **Run:**
   ```cmd
   cd C:\xampp\htdocs\CAPSTONE
   java -Xmx1024m -jar jTessBoxEditor-2.3.1.jar
   ```

**This version works with any Java 8+ installation!** ✅

---

## ✅ Solution 2: Install JavaFX SDK

1. **Download JavaFX SDK:**
   - Go to: https://openjfx.io/
   - Download JavaFX SDK for your Java version
   - Extract to: `C:\javafx-sdk\`

2. **Run with JavaFX:**
   ```cmd
   cd C:\xampp\htdocs\CAPSTONE\jTessBoxEditorFX
   java --module-path "C:\javafx-sdk\lib" --add-modules javafx.controls,javafx.fxml -Xmx1024m -jar jTessBoxEditorFX.jar
   ```

---

## ✅ Solution 3: Use Java 8 (Includes JavaFX)

1. **Download Java 8:**
   - https://www.oracle.com/java/technologies/javase/javase8-archive-downloads.html
   - Install Java 8 (includes JavaFX)

2. **Run:**
   ```cmd
   cd C:\xampp\htdocs\CAPSTONE\jTessBoxEditorFX
   "C:\Program Files\Java\jre1.8.0_xxx\bin\java.exe" -Xmx1024m -jar jTessBoxEditorFX.jar
   ```

---

## ✅ Solution 4: Use Command-Line Training (No GUI)

If GUI tools don't work, use tesstrain command-line tool (more advanced).

---

## 🎯 RECOMMENDED: Use Older jTessBoxEditor

**Easiest solution:** Download `jTessBoxEditor-2.3.1.jar` (non-FX version)

1. **Download:** https://sourceforge.net/projects/vietocr/files/jTessBoxEditor/jTessBoxEditor-2.3.1/jTessBoxEditor.jar/download
2. **Save to:** `C:\xampp\htdocs\CAPSTONE\`
3. **Run:**
   ```cmd
   cd C:\xampp\htdocs\CAPSTONE
   java -Xmx1024m -jar jTessBoxEditor.jar
   ```

This version works perfectly and doesn't need JavaFX! ✅


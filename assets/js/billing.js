// Handle meter image upload and OCR
function handleImageUpload(input) {
    const file = input.files[0];
    if (!file) return;

    // Show preview
    const previewContainer = document.getElementById('previewContainer');
    const imagePreview = document.getElementById('imagePreview');
    const progressBar = previewContainer.querySelector('.progress');
    const progressBarInner = progressBar.querySelector('.progress-bar');
    const ocrResult = document.getElementById('ocrResult');

    // Show preview container and image
    previewContainer.style.display = 'block';
    imagePreview.src = URL.createObjectURL(file);

    // Show progress bar
    progressBar.style.display = 'block';
    progressBarInner.style.width = '0%';
    ocrResult.style.display = 'none';

    // Create FormData and send to server
    const formData = new FormData();
    formData.append('meter_image', file);

    // Send to server
    fetch('process_ocr.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        progressBarInner.style.width = '100%';
        
        if (data.success) {
            // Update reading input
            document.querySelector('input[name="reading"]').value = data.reading;
            
            // Show success message
            ocrResult.style.display = 'block';
            ocrResult.className = 'alert alert-success mt-2';
            ocrResult.textContent = `Detected Reading: ${data.reading}`;
        } else {
            throw new Error(data.message);
        }
    })
    .catch(error => {
        progressBarInner.style.width = '100%';
        progressBar.classList.add('bg-danger');
        
        ocrResult.style.display = 'block';
        ocrResult.className = 'alert alert-danger mt-2';
        ocrResult.textContent = `Error: ${error.message}`;
    })
    .finally(() => {
        setTimeout(() => {
            progressBar.style.display = 'none';
            progressBarInner.style.width = '0%';
            progressBar.classList.remove('bg-danger');
        }, 2000);
    });
} 
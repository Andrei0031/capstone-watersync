// Apply theme immediately before any content loads
(function() {
    const savedTheme = localStorage.getItem('theme') || 'light';
    document.documentElement.setAttribute('data-theme', savedTheme);
})();

// Set up theme toggle functionality after DOM loads
document.addEventListener('DOMContentLoaded', function() {
    const themeToggle = document.getElementById('theme-toggle');
    if (themeToggle) {
        // Set initial state
        themeToggle.checked = localStorage.getItem('theme') === 'dark';

        // Add change listener
        themeToggle.addEventListener('change', function() {
            const theme = this.checked ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', theme);
            localStorage.setItem('theme', theme);
            
            // Broadcast theme change to other pages
            localStorage.setItem('theme_updated', Date.now().toString());
        });
    }

    // Listen for theme changes from other pages
    window.addEventListener('storage', function(e) {
        if (e.key === 'theme' || e.key === 'theme_updated') {
            const currentTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', currentTheme);
            if (themeToggle) {
                themeToggle.checked = currentTheme === 'dark';
            }
        }
    });
}); 
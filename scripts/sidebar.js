// Enhanced Sidebar functionality with smooth transitions
document.addEventListener('DOMContentLoaded', function() {
  // Get current page from URL
  const currentPage = window.location.pathname.split('/').pop();
  
  // Remove active class from all links
  const navLinks = document.querySelectorAll('.nav a');
  navLinks.forEach(link => {
    link.classList.remove('active');
  });
  
  // Add active class to current page link
  navLinks.forEach(link => {
    const linkPage = link.getAttribute('href').split('/').pop();
    if (linkPage === currentPage) {
      link.classList.add('active');
    }
  });
  
  // Handle click events for sidebar links with transitions
  navLinks.forEach(link => {
    link.addEventListener('click', function(e) {
      // Only prevent default for same-page navigation to allow smooth transitions
      if (this.getAttribute('href') && !this.getAttribute('href').startsWith('#')) {
        e.preventDefault();
        
        // Remove active class from all links
        navLinks.forEach(l => l.classList.remove('active'));
        
        // Add active class to clicked link
        this.classList.add('active');
        
        // Get the target page
        const targetPage = this.getAttribute('href');
        
        // Apply page transition
        applyPageTransition(targetPage);
      }
    });
  });
  
  // Mobile menu toggle (if needed in the future)
  const menuToggle = document.createElement('div');
  menuToggle.className = 'menu-toggle';
  menuToggle.innerHTML = '<i class="bx bx-menu"></i>';
  
  // Add to topbar if on mobile
  if (window.innerWidth <= 768) {
    document.querySelector('.topbar').prepend(menuToggle);
    
    menuToggle.addEventListener('click', function() {
      document.querySelector('.sidebar').classList.toggle('active');
    });
  }
  
  // Initialize page transitions on load
  initializePageTransitions();
});

// Page transition function
function applyPageTransition(targetPage) {
  const currentPage = document.querySelector('.page.active');
  const loadingSpinner = document.getElementById('page-loading');
  
  // Show loading spinner
  if (loadingSpinner) {
    loadingSpinner.style.display = 'block';
  }
  
  // Apply exit animation to current page
  if (currentPage) {
    currentPage.classList.remove('active');
    currentPage.classList.add('slide-out');
    
    // Wait for exit animation to complete
    setTimeout(() => {
      // Navigate to new page
      window.location.href = targetPage;
    }, 400);
  } else {
    // If no current page animation, navigate immediately
    window.location.href = targetPage;
  }
}

// Initialize page transitions
function initializePageTransitions() {
  // Add loading spinner to page if it doesn't exist
  if (!document.getElementById('page-loading')) {
    const loadingSpinner = document.createElement('div');
    loadingSpinner.id = 'page-loading';
    loadingSpinner.className = 'page-loading';
    loadingSpinner.innerHTML = '<div class="spinner"></div><p style="margin-top: 10px; color: #3f1378;">Loading...</p>';
    document.body.appendChild(loadingSpinner);
  }
  
  // Add active class to current page with delay for entrance animation
  const currentPage = document.querySelector('.main-content .page') || document.querySelector('.main-content');
  if (currentPage && !currentPage.classList.contains('page')) {
    // Wrap main content in page container if not already
    const pageContainer = document.createElement('div');
    pageContainer.className = 'page-container';
    
    const page = document.createElement('div');
    page.className = 'page active slide-in';
    
    // Move main content into page container
    const mainContent = document.querySelector('.main-content');
    const children = Array.from(mainContent.children);
    
    children.forEach(child => {
      page.appendChild(child);
    });
    
    pageContainer.appendChild(page);
    mainContent.appendChild(pageContainer);
  } else if (currentPage) {
    currentPage.classList.add('active', 'slide-in');
  }
  
  // Hide loading spinner after page load
  setTimeout(() => {
    const loadingSpinner = document.getElementById('page-loading');
    if (loadingSpinner) {
      loadingSpinner.style.display = 'none';
    }
  }, 500);
}

// Enhanced hover effects for sidebar
document.addEventListener('DOMContentLoaded', function() {
  const sidebarLinks = document.querySelectorAll('.sidebar .nav a');
  
  sidebarLinks.forEach(link => {
    link.addEventListener('mouseenter', function() {
      this.style.transform = 'translateX(5px)';
    });
    
    link.addEventListener('mouseleave', function() {
      this.style.transform = 'translateX(0)';
    });
  });
}); 
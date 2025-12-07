<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="stylesheet" href="../styles/index.css" />
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />
  <link rel="icon" type="image/x-icon" href="../imgs/fav.png">
  <title>LOGIN</title>
  <style>
    /* Logo Styling */
    .content-logo {
      width: 100px;
      height: auto;
      margin-bottom: 20px;
      transition: 1s ease-in-out;
    }

    /* Logo positioning and animation */
    .text.sign-in h2,
    .logo.sign-in {
      transform: translateX(-250%);
    }

    .text.sign-up h2,
    .logo.sign-up {
      transform: translateX(250%);
    }

    .container.sign-in .text.sign-in h2,
    .container.sign-in .logo.sign-in,
    .container.sign-up .text.sign-up h2,
    .container.sign-up .logo.sign-up {
      transform: translateX(0);
    }

    /* Office Dropdown Styles */
    .office-dropdown-container {
      transition: all 0.3s ease-in-out;
      overflow: hidden;
    }

    .office-dropdown {
      width: 100%;
      padding: 1rem 3rem;
      font-size: 1rem;
      border-radius: 0.5rem;
      border: 0.125rem solid #ababab;
      outline: none;
      appearance: none;
      cursor: pointer;
      font-family: "Poppins", sans-serif;
      color: #5a5a5a;
      background-color: white;
    }

    .office-dropdown:focus {
      border: 0.125rem solid var(--primary-color);
    }

    .office-dropdown-container .bxs-building {
      position: absolute;
      top: 50%;
      left: 1rem;
      transform: translateY(-50%);
      font-size: 1.4rem;
      color: var(--gray-2);
      pointer-events: none;
      z-index: 1;
    }

    .office-dropdown-container::after {
      content: "▼";
      position: absolute;
      top: 50%;
      right: 1rem;
      transform: translateY(-50%);
      color: var(--gray-2);
      font-size: 0.8rem;
      pointer-events: none;
    }

    /* Proportional Alert Messages */
    .alert {
      position: fixed;
      top: 20px;
      right: 20px;
      padding: 12px 20px;
      border-radius: 8px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
      z-index: 10000;
      animation: slideInRight 0.3s ease-out;
      display: inline-flex;
      align-items: center;
      justify-content: space-between;
      max-width: 80vw;
      word-wrap: break-word;
      white-space: normal;
      width: auto;
      min-width: min-content;
      max-width: min(600px, 80vw);
    }

    .alert-error {
      background: #fee;
      border: 1px solid #f5c6cb;
      color: #721c24;
    }

    .alert-success {
      background: #eff8f0;
      border: 1px solid #c3e6cb;
      color: #155724;
    }

    .alert-content {
      flex: 1;
      margin-right: 15px;
      line-height: 1.4;
    }

    .alert-close {
      background: none;
      border: none;
      font-size: 18px;
      cursor: pointer;
      color: inherit;
      opacity: 0.7;
      transition: opacity 0.2s;
      flex-shrink: 0;
    }

    .alert-close:hover {
      opacity: 1;
    }

    @keyframes slideInRight {
      from {
        transform: translateX(100%);
        opacity: 0;
      }

      to {
        transform: translateX(0);
        opacity: 1;
      }
    }

    .alert.fade-out {
      animation: slideOutRight 0.3s ease-in forwards;
    }

    @keyframes slideOutRight {
      from {
        transform: translateX(0);
        opacity: 1;
      }

      to {
        transform: translateX(100%);
        opacity: 0;
      }
    }
  </style>
</head>

<body>
  <!-- Message Display Section -->
  <?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-error" id="errorMessage">
      <div class="alert-content">
        <strong>Error!</strong> <?php echo $_SESSION['error']; ?>
      </div>
      <button class="alert-close">&times;</button>
    </div>
    <?php unset($_SESSION['error']); ?>
  <?php endif; ?>

  <?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success" id="successMessage">
      <div class="alert-content">
        <strong>Success!</strong> <?php echo $_SESSION['success']; ?>
      </div>
      <button class="alert-close">&times;</button>
    </div>
    <?php unset($_SESSION['success']); ?>
  <?php endif; ?>

  <div id="container" class="container">
    <!-- FORM SECTION -->
    <div class="row">

      <!-- SIGN UP -->
      <div class="col align-items-center flex-col sign-up">
        <form class="form-wrapper align-items-center" id="signup" method="POST" action="../register.php">
          <div class="form sign-up">

            <div class="name-group">
              <div class="input-group">
                <i class="bx bxs-user"></i>
                <input type="text" name="first_name" placeholder="First Name" required />
              </div>

              <div class="input-group">
                <i class="bx bxs-user"></i>
                <input type="text" name="last_name" placeholder="Last Name" required />
              </div>
            </div>

            <div class="input-group">
              <i class="bx bxs-envelope"></i>
              <input type="email" name="email" placeholder="Email" required />
            </div>

            <div class="input-group">
              <i class="bx bxs-lock-alt"></i>
              <input type="password" name="password" placeholder="Password" required />
            </div>

            <div class="input-group">
              <i class="bx bxs-lock-alt"></i>
              <input type="password" name="confirm_password" placeholder="Confirm password" required />
            </div>

            <div class="input-group role-dropdown-container">
              <i class="bx bxs-group"></i>
              <select name="role_id" class="role-dropdown" id="roleSelect" required>
                <option value="">Select Role</option>
                <option value="1">Admin</option>
                <option value="2">Staff</option>
              </select>
            </div>
            <div class="input-group office-dropdown-container" id="officeDropdownContainer" style="display: none;">
              <i class='bx bxs-buildings'></i>
              <select name="office_id" class="office-dropdown" id="officeSelect">
                <option value="">Select Department</option>
                <option value="1">Board of Regents</option>
                <option value="2">Board Secretary</option>
                <option value="3">President Files</option>
                <option value="4">Vice-President for Academic Affairs / Colleges</option>
                <option value="5">Accounting</option>
                <option value="6">Budgeting Services</option>
                <option value="7">Business Affairs Office</option>
                <option value="8">Human Resource Management Office</option>
                <option value="9">Legal Unit</option>
                <option value="10">Planning Office</option>
                <option value="11">Quality Management System (ISO)</option>
                <option value="12">Records Management Office</option>
                <option value="13">Registrar's Office</option>
                <option value="14">Scholarship and Financial Assistance Unit</option>
                <option value="15">Supply Office</option>
                <option value="16">University Health Service</option>
                <option value="17">University Library</option>
              </select>
            </div>


            <button type="submit" value="Sign Up" name="signUp">Sign up</button>

            <p>
              <span> Already have an account? </span>
              <b onclick="toggle()" class="pointer"> Sign in here </b>
            </p>

          </div>
        </form>
      </div>
      <!-- END SIGN UP -->

      <!-- SIGN IN -->
      <div class="col align-items-center flex-col sign-in">
        <form class="form-wrapper align-items-center" method="POST" action="../login_process.php">
          <div class="form sign-in">

            <div class="input-group">
              <i class="bx bxs-envelope"></i>
              <input type="text" name="email" placeholder="Email" required />
            </div>

            <div class="input-group">
              <i class="bx bxs-lock-alt"></i>
              <input type="password" name="password" placeholder="Password" required />
            </div>

            <button type="submit">Sign in</button>

            <p><b> Forgot password? </b></p>

            <p>
              <span> Don't have an account? </span>
              <b onclick="toggle()" class="pointer"> Sign up here </b>
            </p>

          </div>
        </form>
      </div>
      <!-- END SIGN IN -->
    </div>

    <!-- CONTENT SECTION -->
    <div class="row content-row">

      <!-- SIGN IN CONTENT -->
      <div class="col align-items-center flex-col left">
        <div class="img sign-in">
          <img src="../imgs/RDS.png" alt="RDS Logo" class="content-logo logo sign-in">
        </div>
        <div class="text sign-in">
          <h2>RECORD DISPOSAL SYSTEM</h2>
        </div>
      </div>

      <!-- SIGN UP CONTENT -->
      <div class="col align-items-center flex-col right">
        <div class="text sign-up">
          <div class="img sign-up">
            <img src="../imgs/RDS.png" alt="RDS Logo" class="content-logo logo sign-up">
          </div>
          <h2>RECORD DISPOSAL SYSTEM</h2>
        </div>
      </div>

    </div>
  </div>

  <script>
    let container = document.getElementById("container");

    toggle = () => {
      container.classList.toggle("sign-in");
      container.classList.toggle("sign-up");
    };

    // Check if we should show signup form
    const urlParams = new URLSearchParams(window.location.search);
    const shouldShowSignup = urlParams.get('form') === 'signup';

    if (shouldShowSignup) {
      container.classList.add("sign-up");
    } else {
      setTimeout(() => {
        container.classList.add("sign-in");
      }, 200);
    }

    // Office dropdown functionality
    document.addEventListener('DOMContentLoaded', function () {
      const roleSelect = document.getElementById("roleSelect");
      const officeContainer = document.getElementById("officeDropdownContainer");

      console.log('Script loaded - Elements:', {
        roleSelect: !!roleSelect,
        officeContainer: !!officeContainer
      });

      if (roleSelect && officeContainer) {
        // Function to handle office dropdown visibility
        function updateOfficeDropdown() {
          // Show office dropdown for Staff role (value 2)
          if (roleSelect.value === '2') {
            officeContainer.style.display = 'block';
            document.getElementById('officeSelect').setAttribute('required', 'required');
            console.log('Showing office dropdown for Staff');
          } else {
            officeContainer.style.display = 'none';
            document.getElementById('officeSelect').removeAttribute('required');
            console.log('Hiding office dropdown for Admin');
          }
        }

        roleSelect.addEventListener('change', updateOfficeDropdown);

        // Initialize office dropdown based on current role selection
        updateOfficeDropdown();
      } else {
        console.error('Missing elements:', {
          roleSelect: !roleSelect,
          officeContainer: !officeContainer
        });
      }

      // Auto-hide alert messages functionality
      const alerts = document.querySelectorAll('.alert');
      alerts.forEach(alert => {
        // Auto-hide after 5 seconds
        setTimeout(() => {
          alert.classList.add('fade-out');
          setTimeout(() => {
            if (alert.parentNode) {
              alert.parentNode.removeChild(alert);
            }
          }, 300);
        }, 5000);

        // Close button functionality
        const closeBtn = alert.querySelector('.alert-close');
        if (closeBtn) {
          closeBtn.addEventListener('click', function () {
            alert.classList.add('fade-out');
            setTimeout(() => {
              if (alert.parentNode) {
                alert.parentNode.removeChild(alert);
              }
            }, 300);
          });
        }
      });
    });
  </script>
</body>

</html>
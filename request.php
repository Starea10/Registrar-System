<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Request Document | CvSU Naic Registrar</title>
  <link rel="icon" href="assets/images/logo.png" type="image/x-icon">

  <!-- Stylesheets -->
  <link rel="stylesheet" href="css/variables.css">
  <link rel="stylesheet" href="css/style.css">
  <link rel="stylesheet" href="css/request.css">
</head>

<body style="background: var(--background);">
  <header style="position: relative; box-shadow: none;">
    <nav>
      <div class="container nav">
        <a href="index.html" class="logo">
          <img src="assets/images/logo.png" alt="Logo">
          <span>CvSU Naic Registrar</span>
        </a>
        <a href="index.html" class="back">← Back Home</a>
      </div>
    </nav>
  </header>

  <main class="container">
    <div class="page-title">
      <h1>Request Academic Document</h1>
      <p>Complete the form below to request an official academic document.</p>
    </div>

    <div class="stepper">
      <div class="step active">
        <div class="circle">1</div>
        <p>Student Info</p>
      </div>
      <div class="line"></div>
      <div class="step">
        <div class="circle">2</div>
        <p>Document Details</p>
      </div>
      <div class="line"></div>
      <div class="step">
        <div class="circle">3</div>
        <p>Confirmation</p>
      </div>
    </div>

    <form id="requestForm">
      <!-- STEP 1 - STUDENT INFORMATION -->
      <section class="form-step active">
        <h2>Student Information</h2>
        <div class="grid-2">
          <div class="input-group">
            <label>Student Number</label>
            <input type="text" name="Student Number" placeholder="2023-00001">
          </div>
          <div class="input-group">
            <label>Student Email</label>
            <input type="email" name="Email" placeholder="juan.delacruz@cvsu.edu.ph">
          </div>
          <div class="input-group">
            <label>First Name</label>
            <input type="text" name="First Name">
          </div>
          <div class="input-group">
            <label>Last Name</label>
            <input type="text" name="Last Name">
          </div>
          <div class="input-group">
            <label>Middle Name</label>
            <input type="text" name="Middle Name">
          </div>
          <div class="input-group">
            <label>Course</label>
            <select name="Course">
              <option value="">Select Course</option>
              <option>BS Computer Science</option>
              <option>BS Information Technology</option>
              <option>BSEd</option>
              <option>BSBA</option>
            </select>
          </div>
          <div class="input-group full">
            <label>Complete Address</label>
            <textarea name="Address" rows="3"></textarea>
          </div>
        </div>
        <div class="buttons">
          <button type="button" class="next">Next →</button>
        </div>
      </section>

      <!-- STEP 2 - DOCUMENT DETAILS -->
      <section class="form-step">
        <h2>Document Details</h2>
        <div class="grid-2">
          <div class="input-group">
            <label>Document Type</label>
            <select name="Document">
              <option>Certificate of Enrollment</option>
              <option>Certificate of Grades</option>
              <option>Transcript of Records</option>
            </select>
          </div>
          <div class="input-group">
            <label>Purpose</label>
            <select name="Purpose">
              <option>Employment</option>
              <option>Scholarship</option>
              <option>Transfer</option>
            </select>
          </div>
          <div class="input-group full">
            <label>Additional Notes</label>
            <textarea name="Notes" rows="3"></textarea>
          </div>
        </div>
        <div class="buttons">
          <button type="button" class="previous">← Previous</button>
          <button type="button" class="next">Next →</button>
        </div>
      </section>

      <!-- STEP 3 - CONFIRMATION -->
      <section class="form-step">
        <h2>Confirmation</h2>
        <div class="confirmation">
          <h3>Please review your information.</h3>
          <p>Ensure all information is correct before submitting.</p>
          <div class="summary">
            <!-- JS inserts summary here -->
          </div>
          <label class="checkbox">
            <input type="checkbox">
            I certify that all information provided is true and correct.
          </label>
        </div>
        <div class="buttons">
          <button type="button" class="previous">← Previous</button>
          <button type="submit" class="submit" >Submit Request</button>
        </div>
      </section>
    </form>
  </main>

  <script src="js/request.js"></script>
</body>
</html>
<?php 
  session_start();
  require_once 'includes/config.php';

  $sql = "SELECT * FROM `programs`";

  try {
    $programs = $conn->query($sql);
  } catch (\Throwable $th) {
    echo($th);
  }
  
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Request Document | CvSU Naic Registrar</title>
  <link rel="icon" href="assets/images/logo.png" type="image/x-icon">

  <!-- Stylesheets -->
  <link rel="stylesheet" href="assets/css/variables.css">
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="assets/css/request.css">
</head>

<body style="background: var(--background);">
  <header style="position: relative; box-shadow: none;">
    <nav>
      <div class="container nav">
        <a href="index.php" class="logo">
          <img src="assets/images/logo.png" alt="Logo">
          <span>CvSU Naic Registrar</span>
        </a>
        <a href="index.php" class="back">← Back Home</a>
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
            <input type="text" inputmode="numeric" pattern="[0-9]*" placeholder='202310000' >
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
            <select name="Program">
              <option value="">Select Course</option>
              <?php foreach ($programs as $program): ?>
                <option value="<?= $program['program']; ?>"><?= htmlspecialchars($program['program_name']); ?></option>
                <?php endforeach; ?>
                <option value="others">Others</option>
              </select>
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
                <option>Transcript of Record (TOR)</option>
                <option>Diploma</option>
                <option>Certificate of Grades (COG)</option>
                <option>Certificate of Enrollment (COE)</option>
                <option>Form 137A</option>
                <option>Certification Authentication and Verification (CAV)</option>
                <option>Certification</option>
                <option>Others</option>
              </select>
              <input type="text" name="others_type" id="others_type" class="form-control mt-2" 
                                           placeholder="Specify document here" style="display: none;">
            </div>
            <div class="input-group">
              <label>Purpose</label>
              <select name="Purpose">
                <option value="Employment">Employment</option>
                <option value="Scholarship">Scholarship</option>
                <option value="Board Exam">Board Exam</option>
                <option value="Transfer">Transfer</option>
                <option value="Others">Others</option>
              </select>
              <textarea name="others_purpose" id="others_purpose" style="display: none;" class="form-control" rows="3" placeholder="Enter the purpose of your request"></textarea>
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

<script src="assets/js/request-online.js"></script>
</body>
</html>
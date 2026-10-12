<!-- /**
 * Request Online
 *
 * Server-side page logic for the registrar system. This file handles
 * the request flow, validation rules, and presentation logic for the
 * related administrative or public-facing workflow.
 */ -->

<?php 
  session_start();
  require_once 'includes/config.php';

  // One-time token for submit-online-request.php to check, so the form
  // can't be replayed/resubmitted from a saved copy of the page.
  if (empty($_SESSION['online_form_token'])) {
    $_SESSION['online_form_token'] = bin2hex(random_bytes(32));
  }

  $sql = "SELECT * FROM `programs`";

    try {
        $programs = $conn->query($sql);
    } catch (\Throwable $th) {
        error_log('request-online.php: failed to load programs -- ' . $th->getMessage());
        http_response_code(500);
        die('Sorry, something went wrong loading this page. Please try again shortly.');
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
  <link rel="stylesheet" href="assets/css/variables.css?v=20260921">
  <link rel="stylesheet" href="assets/css/style.css?v=20260921">
  <link rel="stylesheet" href="assets/css/request.css?v=20260921">
</head>

<body style="background: var(--background);">
  <header style="position: relative; box-shadow: none;">
    <nav>
      <div class="container nav">
        <a href="index.php" class="logo">
          <img src="assets/images/logo.png" alt="Logo">
          <span>CvSU Naic Registrar</span>
        </a>
        <a href="index.php" class="back">← Back</a>
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
    
    <form id="requestForm" enctype="multipart/form-data">
      <!-- One-time token, checked by submit-online-request.php -->
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['online_form_token']); ?>">

      <!-- Honeypot: hidden from real visitors via CSS; bots that fill in
           every field on the page will trip it, so requests with this
           filled in are silently discarded server-side. -->
      <div style="position:absolute; left:-9999px; top:-9999px;" aria-hidden="true">
        <label for="website">Leave this field blank</label>
        <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
      </div>

      <!-- STEP 1 - STUDENT INFORMATION -->
      <section class="form-step active">
        <h2>Student Information</h2>
        <div class="grid-2">
          <div class="input-group">
            <label>Student Number</label>
            <input 
                type="text" 
                name="Student Number"
                inputmode="numeric" 
                pattern="[0-9]*" 
              maxlength="15"
              placeholder="202310000"
              oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                required>
          </div>
          <div class="input-group">
            <label>Contact Number</label>
            <input type="tel" name="Contact Number" inputmode="numeric" pattern="[0-9]+" maxlength="15" placeholder="09123456789" oninput="this.value = this.value.replace(/[^0-9]/g, '')" required>
          </div>
          <div class="input-group">
            <label>Student Email</label>
            <input type="email" name="Email" placeholder="juan.delacruz@cvsu.edu.ph" required>
          </div>
          <div class="input-group">
            <label>First Name</label>
            <input type="text" name="First Name" required>
          </div>
          <div class="input-group">
            <label>Last Name</label>
            <input type="text" name="Last Name" required>
          </div>
          <div class="input-group">
            <label>Middle Name</label>
            <input type="text" name="Middle Name">
          </div>
          <div class="input-group full">
            <label>Course</label>
            <select name="Program" id="program" required>
              <option value="">Select Course</option>
              <?php foreach ($programs as $program): ?>
                <option value="<?= $program['program']; ?>">
                  <?= htmlspecialchars($program['program_name']); ?>
                </option>
              <?php endforeach; ?>
              <option value="others">Others</option>
            </select>
          </div>

          <div class="input-group" id="otherCourseGroup" style="display:none;">
              <label>Please specify your course</label>
              <input
                  type="text"
                  id="Course"
                  name="Course"
                  placeholder="Enter your course">
          </div>
        </div><!-- /.grid-2 (was never closed, so the Next button sat inside the grid) -->
          <div class="buttons">
            <button type="button" class="next">Next →</button>
          </div>
        </section>
        
<!-- STEP 2 - DOCUMENT DETAILS -->
<section class="form-step">
    <h2>Document Details</h2>

    <div class="grid-2">

        <!-- Document Type -->
        <div class="input-group">
            <label>Document Type</label>
            <select name="Document" id="documentType" required>
                <option value="TOR">Transcript of Record (TOR)</option>
                <!-- <option value="Diploma">Diploma</option> -->
                <option value="COG">Certificate of Grades (COG)</option>
                <option value="COE">Certificate of Enrollment (COE)</option>
                <!-- <option value="Form137">Form 137A</option> -->
                <option value="CAV">Certification Authentication and Verification (CAV)</option>
                <option value="Certification">Certification</option>
                <option value="Others">Others</option>
            </select>
        </div>

        <!-- Hidden until "Others" is selected -->
        <div class="input-group" id="otherDocumentGroup" style="display:none;">
            <label>Please specify the document</label>
            <input
                type="text"
                id="otherDocument"
                name="others_type"
                placeholder="Enter document name">
        </div>

        <!-- Purpose -->
        <div class="input-group">
            <label>Purpose</label>

            <select name="Purpose" required>
                <option value="Employment">Employment</option>
                <option value="Scholarship">Scholarship</option>
                <option value="Board Exam">Board Exam</option>
                <option value="Transfer">Transfer</option>
                <option value="Others">Others</option>
            </select>

            <textarea
                name="others_purpose"
                id="others_purpose"
                rows="3"
                placeholder="Enter the purpose"
                style="display:none;">
            </textarea>
        </div>

    </div>

    <!-- NEW DYNAMIC REQUIREMENTS SECTION -->
    <div id="documentRequirements">

        <!-- TOR requirements -->
        <section class="doc-requirements" data-doc="TOR" style="display:none;">
            <h3>Required Documents</h3>
            <div class="input-group" id="torRequestTypeGroup">
                <label>Is this your first time requesting a TOR?</label>
                <label class="radio-inline">
                    <input type="radio" name="tor_request_type" value="first_time" data-required="1"> First time
                </label>
                <label class="radio-inline">
                    <input type="radio" name="tor_request_type" value="previous" data-required="1"> I've requested one before
                </label>
                <!-- Shown by request-online.js when this is required and
                     nothing is picked yet. Styled inline (not via the
                     shared .error class) so it's visible no matter what
                     request.css does with radio inputs specifically. -->
                <p id="torRequestTypeError" style="display:none; color:#b02a37; font-size:0.85rem; margin:6px 0 0;">
                    Please choose one option above.
                </p>
            </div>

            <!-- TOR first-time fields -->
            <div id="torFirstTimeFields" style="display:none;">
                <div class="input-group">
                    <label>Valid CvSU Student ID (image)</label>
                    <input type="file" name="student_id_image" accept="image/jpeg,image/png">
                </div>
                <div class="input-group">
                    <label>Clearance Form (image)</label>
                    <input type="file" name="clearance_form_image" accept="image/jpeg,image/png">
                </div>
                <div class="input-group">
                    <label>Proof of Graduation (image)</label>
                    <input type="file" name="proof_of_graduation_image" accept="image/jpeg,image/png">
                </div>
                <div class="input-group">
                    <label class="checkbox">
                        <input type="checkbox" id="torBoardExam" name="tor_board_exam" value="1"> For board examination
                    </label>
                </div>
                <div class="input-group" id="torIdPictureGroup" style="display:none;">
                    <label>2x2 Passport ID Picture (image)|White background|No Smile</label>
                    <input type="file" name="id_picture_image" accept="image/jpeg,image/png">
                </div>
                <div class="input-group">
                    <label class="checkbox">
                        <input type="checkbox" id="torTransferee" name="tor_transferee" value="1"> I am a transferee
                    </label>
                </div>
                <div class="input-group" id="torHonorableDismissalGroup" style="display:none;">
                    <label>Honorable Dismissal (image)</label>
                    <input type="file" name="honorable_dismissal_image" accept="image/jpeg,image/png">
                </div>
            </div>

            <!-- TOR previous fields -->
            <div id="torPreviousFields" style="display:none;">
                <div class="input-group">
                    <label>Valid CvSU Student ID (image)</label>
                    <input type="file" name="student_id_image" accept="image/jpeg,image/png">
                </div>
                <div class="input-group">
                    <label class="checkbox">
                        <input type="checkbox" name="tor_previous_confirmed" value="1" id="torPreviousConfirmed" data-required="1">
                        I have requested a TOR before
                    </label>
                </div>
            </div>

            <p class="doc-reminder"><em>Required documents must be submitted to the Campus Registrar before the TOR can be released.</em></p>
        </section>

        <!-- COE requirements -->
        <section class="doc-requirements" data-doc="COE" style="display:none;">
            <h3>Required Documents</h3>
            <div class="input-group">
                <label>Valid CvSU Student ID (image)</label>
                <input type="file" name="student_id_image" accept="image/jpeg,image/png">
            </div>
            <div class="input-group">
                <label>Registration Form (image)</label>
                <input type="file" name="registration_form_image" accept="image/jpeg,image/png">
            </div>
        </section>

        <!-- COG requirements -->
        <section class="doc-requirements" data-doc="COG" style="display:none;">
            <h3>Required Documents</h3>
            <div class="input-group">
                <label>Valid CvSU Student ID (image)</label>
                <input type="file" name="student_id_image" accept="image/jpeg,image/png">
                <small>Your student number must be visible in the photo.</small>
            </div>
        </section>

        <!-- CAV requirements -->
        <section class="doc-requirements" data-doc="CAV" style="display:none;">
            <h3>Required Documents</h3>
            <div class="input-group">
                <label>Government-issued ID (image)</label>
                <input type="file" name="government_id_image" accept="image/jpeg,image/png">
            </div>
            <div class="input-group">
                <label>Original Document (image)</label>
                <input type="file" name="original_document_image" accept="image/jpeg,image/png">
            </div>
            <div class="input-group">
                <label>Photocopy of Document — Set 1 (image)</label>
                <input type="file" name="photocopy_document_image" accept="image/jpeg,image/png">
            </div>
            <div class="input-group">
                <label>Photocopy of Document — Set 2 (image)</label>
                <input type="file" name="photocopy_document_image_2" accept="image/jpeg,image/png">
            </div>
            <p class="doc-reminder"><em>Please submit the required documents to the Campus Registrar as soon as possible. Failure to submit the documents may result in the cancellation of the request.</em></p>
        </section>

    </div>

    <!-- Additional Notes -->
    <div class="input-group full">
        <label>Additional Notes</label>
        <textarea name="Notes" rows="3"></textarea>
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
          <input type="checkbox" id="certifyCheckbox">
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

<script src="assets/js/request-online.js?v=20260921"></script>
</body>
</html>
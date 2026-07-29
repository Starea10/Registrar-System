<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CvSU Naic Registrar</title>
  <link rel="icon" href="assets/images/logo.png" type="image/x-icon">

  <!-- Stylesheets -->
  <link rel="stylesheet" href="assets/css/variables.css">
  <link rel="stylesheet" href="assets/css/styles.css">
  <!-- <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/assets/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous"> -->

</head>

<body>
  <!-- ===================================
  NAVBAR
  =================================== -->
  <header>
    <nav class="navbar">
      <div class="container">
        <a href="index.php" class="logo">
          <img src="assets/images/logo.png" alt="Logo">
          <span>CvSU Naic Registrar</span>
        </a>

        <ul class="nav-links">
          <li><a href="#hero" class="active">Home</a></li>
          <li><a href="#office">About</a></li>
          <li><a href="#services">Services</a></li>
          <li><a href="#">FAQ</a></li>
          <li><a href="#footer  ">Contact</a></li>
        </ul>

        <div class="nav-buttons">
          <a href="login-page.php" class="btn-outline">→ Staff Login</a>
          <a href="request-online.php" class="btn-primary">Request Document</a>
        </div>

        <div class="menu-btn">☰</div>
      </div>
    </nav>
  </header>

<!-- ===================================
HERO SECTION (Updated with Tracker Button)
=================================== -->
<section class="hero" id="hero">
  <div class="container hero-grid">
    <div class="hero-text">
      <p class="small-title">CAVITE STATE UNIVERSITY NAIC</p>
      <h1>Office of the Campus Registrar</h1>
      <p>Request official academic documents online, track their progress, and receive updates in real time without visiting the Registrar's Office.</p>
      <div class="hero-buttons">
        <a href="request-online.php" class="btn-primary" style="background: var(--secondary); color: var(--text);">Get Started</a>
        <a href="track.php" class="btn-primary" style="background: var(--primary); color: #fff;">🔍 Track Request</a>
        <a href="#services" class="btn-outline">Learn More</a>
      </div>
    </div>
    <div class="hero-image">
      <img src="assets/images/hero.jpg" class="slide active" alt="Students">
      <img src="assets/images/hero2.jpg" class="slide" alt="Students">
      <img src="assets/images/hero3.jpg" class="slide" alt="Students">
      <img src="assets/images/hero4.jpg" class="slide" alt="Students">
      <img src="assets/images/hero5.jpg" class="slide" alt="Students">

    </div>
  </div>
</section>

  <!-- ===================================
  OFFICE
  =================================== -->
  <section class="office" id="office">
    <div class="container">
      <h2>Office At A Glance</h2>
      <p class="section-description">Providing efficient academic record services for every CvSU Naic student.</p>
      <div class="office-grid">
        <div class="office-card">
          <h3>Office Hours</h3>
          <p>Monday - Thursday<br>7:00 AM - 6:00 PM</p>
        </div>
        <div class="office-card">
          <h3>Contact Information</h3>
          <p>registrar@cvsu-naic.edu.ph</p>
          <p>0976 592 7310</p>
        </div>
        <div class="office-card">
          <h3>Location</h3>
          <p>CvSU Naic Campus<br>Brgy. Bucana Malaki, Naic, Cavite, 4110.</p>

        </div>
      </div>
    </div>
  </section>

<!-- ===================================
AVAILABLE DOCUMENTS SECTION
=================================== -->
<section class="services" id="services">
    <div class="container">

        <h2>Available Documents</h2>
        <p class="section-description">
            Select a document to view details, requirements, and processing times, or request directly.
        </p>

        <div class="services-carousel">

            <!-- Previous Button -->
            <button class="carousel-btn prev" onclick="scrollCarousel(-1)">
                &#10094;
            </button>

            <!-- Cards -->
            <div class="services-grid" id="servicesGrid">

                <!-- Certificate of Enrollment -->
                <div class="service-card">
                    <div class="icon">📄</div>
                    <h3>Certificate of Enrollment</h3>
                    <p class="doc-summary">
                        Official proof of active registration, enrolled units,
                        and current academic standing.
                    </p>
                    <div class="doc-badge">
                        ⏱ Processing: 2-3 Working Days
                    </div>

                    <div class="card-actions">
                        <button class="btn-info"
                            onclick="openDocModal('enrollment')">
                            ℹ️ View Info
                        </button>

                        <a href="request-online.php?doc=Certificate%20of%20Enrollment"
                            class="btn-card-request">
                            Request Document →
                        </a>
                    </div>
                </div>

                <!-- Transcript of Records -->
                <div class="service-card">
                    <div class="icon">🎓</div>
                    <h3>Transcript of Records</h3>
                    <p class="doc-summary">
                        Complete academic history detailing all subjects,
                        grades, units, and degree status.
                    </p>

                    <div class="doc-badge">
                        ⏱ Processing <br> First Request: 20 Working Days<br>Second Request & Onwards: 7 Working Days
                    </div>

                    <div class="card-actions">
                        <button class="btn-info"
                            onclick="openDocModal('tor')">
                            ℹ️ View Info
                        </button>

                        <a href="request-online.php?doc=Transcript%20of%20Records"
                            class="btn-card-request">
                            Request Document →
                        </a>
                    </div>
                </div>

                <!-- Certificate of Grades -->
                <div class="service-card">
                    <div class="icon">📜</div>
                    <h3>Certificate of Grades</h3>
                    <p class="doc-summary">
                        Official record of evaluation grades achieved
                        for a specific semester or academic year.
                    </p>

                    <div class="doc-badge">
                        ⏱ Processing: 2-3 Working Days
                    </div>

                    <div class="card-actions">
                        <button class="btn-info"
                            onclick="openDocModal('grades')">
                            ℹ️ View Info
                        </button>

                        <a href="request-online.php?doc=Certificate%20of%20Grades"
                            class="btn-card-request">
                            Request Document →
                        </a>
                    </div>
                </div>

                <!-- CAV -->
                <div class="service-card">
                    <div class="icon">🌏︎</div>
                    <h3> Certification Authentication and Verification (CAV)</h3>

                    <p class="doc-summary">
                        Certification and verification of official
                        school records for legal or abroad use.
                    </p>

                    <div class="doc-badge">
                        ⏱ Processing: 7 Working Days
                    </div>

                    <div class="card-actions">
                        <button class="btn-info"
                            onclick="openDocModal('cav')">
                            ℹ️ View Info
                        </button>

                        <a href="request-online.php?doc=cav"
                            class="btn-card-request">
                            Request Document →
                        </a>
                    </div>
                </div>

                <!-- CTC -->
                <div class="service-card">
                    <div class="icon">✔</div>
                    <h3>Certified True Copy</h3>

                    <p class="doc-summary">
                        Certified true copy of official
                        school records for scholarships, and etc..
                    </p>

                    <div class="doc-badge">
                        ⏱ Processing: Walk-In
                    </div>

                    <div class="card-actions">
                        <button class="btn-info"
                            onclick="openDocModal('ctc')">
                            ℹ️ View Info
                        </button>

                    </div>
                </div>

                <!-- Good Moral -->
                <div class="service-card">
                    <div class="icon">🏅</div>
                    <h3>Good Moral Certificate</h3>

                    <p class="doc-summary">
                        Official certification of the student's good moral character.
                    </p>

                    <div class="doc-badge">
                        ⏱ Processing: Walk-In
                    </div>

                    <div class="card-actions">
                        <button class="btn-info"
                            onclick="openDocModal('good_moral')">
                            ℹ️ View Info
                        </button>

                    </div>
                </div>

            </div>

            <!-- Next Button -->
            <button class="carousel-btn next" onclick="scrollCarousel(1)">
                &#10095;
            </button>

        </div>

    </div>
</section>

<!-- ===================================
DOCUMENT INFO MODAL (Popup Viewer)
=================================== -->
<div id="docModal" class="modal-overlay">
  <div class="modal-content">
    <button class="modal-close" onclick="closeDocModal()">&times;</button>
    <div class="modal-header">
      <span id="modalIcon" class="modal-icon">📄</span>
      <h2 id="modalTitle">Document Title</h2>
    </div>
    <div class="modal-body">
      <p id="modalDescription"></p>
      
      <div class="info-block">
        <h4>📋 Requirements:</h4>
        <ul id="modalRequirements"></ul>
      </div>

      <div class="info-block">
        <h4>⏳ Processing Time:</h4>
        <p id="modalProcessing"></p>
      </div>

      <div class="info-block">
        <h4>💡 Note:</h4>
        <p id="modalNote"></p>
      </div>
    </div>
    <div class="modal-footer">
      <a id="modalRequestBtn" href="#" class="btn-primary" style="background: var(--secondary); color: var(--text); width: 100%; text-align: center;">Request This Document Now →</a>
    </div>
  </div>
</div>

  <!-- ===================================
  HOW IT WORKS
  =================================== -->
  <section class="steps">
    <div class="container">
      <h2>How It Works</h2>
      <div class="steps-grid">
        <div class="step">
          <div class="number">1</div>
          <h3>Submit Request</h3>
          <p>Fill up the online form.</p>
        </div>
        <div class="step">
          <div class="number">2</div>
          <h3>Processing</h3>
          <p>Registrar reviews your request.</p>
        </div>
        <div class="step">
          <div class="number">3</div>
          <h3>Claim</h3>
          <p>Receive email notification.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- ===================================
  INFORMATION
  =================================== -->
  <section class="information">
    <div class="container">
      <div class="info-card">
        <h3>Requirements</h3>
        <p>Bring a valid school ID when claiming your requested documents.</p>
      </div>
      <div class="info-card">
        <h3>Processing Time</h3>
        <p>Usually 3-20 working days depending on document type.</p>
      </div>
      <div class="info-card">
        <h3>Need Help?</h3>
        <p>Contact the Registrar during office hours.</p>
      </div>
    </div>
  </section>

  <!-- ===================================
  FOOTER
  =================================== -->
  <footer>
    <div class="container footer-grid" id="footer">
      <div>
        <h3>CvSU Naic Registrar</h3>
        <p>Providing quality academic services.</p>
      </div>
      <div>
        <h4>Quick Links</h4>
        <ul>
          <li><a href="#hero" class="active">Home</a></li>
          <li><a href="#office">About</a></li>
          <li><a href="#footer  ">Contact</a></li>
        </ul>
      </div>
      <div>
        <h4>Contact</h4>
        <p>registrar@cvsu-naic.edu.ph</p>
        <p>0976 592 7310</p>
        <p>https://www.facebook.com/cvsunaicregistrar/</p>
      </div>
    </div>
    <div class="copyright">© 2026 CvSU Naic Registrar</div>
  </footer>

  <button id="topBtn">↑</button>
  <script src="assets/js/main.js"></script>
</body>
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script> -->
</html>
/*=====================================
    MOBILE MENU
=====================================*/

const menuBtn = document.querySelector(".menu-btn");
const navLinks = document.querySelector(".nav-links");
const navButtons = document.querySelector(".nav-buttons");

menuBtn.addEventListener("click", () => {

    navLinks.classList.toggle("active");
    navButtons.classList.toggle("active");
    menuBtn.classList.toggle("open");

});

/*=====================================
    CLOSE MENU WHEN LINK IS CLICKED
=====================================*/

document.querySelectorAll(".nav-links a").forEach(link => {

    link.addEventListener("click", () => {

        navLinks.classList.remove("active");
        navButtons.classList.remove("active");
        menuBtn.classList.remove("open");

    });

});

/*=====================================
    STICKY NAVBAR
=====================================*/

const header = document.querySelector("header");

window.addEventListener("scroll", () => {

    if(window.scrollY > 40){

        header.classList.add("scrolled");

    }

    else{

        header.classList.remove("scrolled");

    }

});

/*=====================================
    SMOOTH SCROLL
=====================================*/

document.querySelectorAll('a[href^="#"]').forEach(anchor=>{

    anchor.addEventListener("click",function(e){

        e.preventDefault();

        const target=document.querySelector(this.getAttribute("href"));

        if(target){

            target.scrollIntoView({

                behavior:"smooth"

            });

        }

    });

});

/*=====================================
    BACK TO TOP
=====================================*/

const topBtn = document.getElementById("topBtn");

window.addEventListener("scroll",()=>{

    if(window.scrollY>500){

        topBtn.style.display="block";

    }

    else{

        topBtn.style.display="none";

    }

});

topBtn.addEventListener("click",()=>{

    window.scrollTo({

        top:0,

        behavior:"smooth"

    });

});

/*=====================================
    SCROLL ANIMATION
=====================================*/

const observer = new IntersectionObserver(entries=>{

    entries.forEach(entry=>{

        if(entry.isIntersecting){

            entry.target.classList.add("show");

        }

    });

},{
    threshold:.15
});

document.querySelectorAll("section").forEach(section=>{

    section.classList.add("fade");

    observer.observe(section);

});

/*=====================================
    ACTIVE NAVIGATION
=====================================*/

const sections=document.querySelectorAll("section");
const navItems=document.querySelectorAll(".nav-links a");

window.addEventListener("scroll",()=>{

    let current="";

    sections.forEach(section=>{

        const top=section.offsetTop-120;

        const height=section.clientHeight;

        if(pageYOffset>=top){

            current=section.getAttribute("id");

        }

    });

    navItems.forEach(link=>{

        link.classList.remove("active");

        if(link.getAttribute("href")==="#"+current){

            link.classList.add("active");

        }

    });

});

/*=====================================
    COUNT UP
=====================================*/

const counters=document.querySelectorAll(".counter");

counters.forEach(counter=>{

    counter.innerText="0";

    const update=()=>{

        const target=+counter.dataset.target;
        const current=+counter.innerText;

        const increment=target/100;

        if(current<target){

            counter.innerText=Math.ceil(current+increment);

            requestAnimationFrame(update);

        }else{

            counter.innerText=target;

        }

    };

    update();

});

// Document Data Store for Modal Popup
const documentInfo = {
  enrollment: {
    title: "Certificate of Enrollment",
    icon: "📄",
    description: "Serves as official certification that the student is currently enrolled in Cavite State University - Naic Campus.",
    requirements: [
      "Valid Student ID",
      "Currently Enrolled in a semester",
      "Cleared accounts/no outstanding library or department balance",
      "Official receipt of current semester tuition/fees (if applicable)"
    ],
    processing: "2-3 Working Days",
    note: "Must be requested by the student or an authorized representative with a formal authorization letter and photocopy of the student's valid id."
  },
  tor: {
    title: "Transcript of Records",
    icon: "🎓",
    description: "Official transcript displaying complete scholastic records, coursework, grades, and cumulative GPA.",
    requirements: [
      "For First Request:",  
      "Duly accomplished Registrar Clearance Form",
      "Student Has Graduated",
      "2x2 recent ID picture (White background with name tag) (for Board Exam takers)",
      "Photocopy of Honorable Dismissal (for transfer students)",
    
        "---",

      "For Second Request and Onwards:",  
      "Already Requested a TOR before"
    ],
    processing: "First Request: 20 Working Days\n Second Request & Onwards: 7 Working Days",
    note: "Must be requested by the student or an authorized representative with a formal authorization letter and photocopy of the student's valid id."
  },
  grades: {
    title: "Certificate of Grades",
    icon: "📜",
    description: "Official summary of grades earned for a specific academic term or school year.",
    requirements: [
      "Student Number and Course details",
      "Settled departmental clearances for the target semester"
    ],
    processing: "2 to 3 Working Days",
    note: "Useful for scholarship applications, credit transfers, and personal evaluation."
  },
  cav: {
    title: "Certification Authentication and Verification (CAV)",
    icon: "🌏︎",
    description: "Verification and authentication of official school documents for employment, DFA apostille, or overseas studies.",
    requirements: [
      "Original Document to be authenticated",
      "Photocopies of the document (2 sets)",
      "Valid Government Issued ID"
    ],
    processing: "7 Working Days",
    note: "Original copies must be presented to the Registrar's Office during document pickup."
  },
  ctc: {
    title: "Certified True Copy (CTC)",
    icon: "✔",
    description: "Certified true copy of official school records for scholarships, and etc..",
    requirements: [
      "Photocopy of Document to be CTC",
    ],
    processing: "Walk-Ins Only",
    note: "Photocopy must be provided by requester for CTC"
  },
  good_moral: {
    title: "Good Moral Certificate",
    icon: "🏅",
    description: "Official certification of the student's good moral character.",
    requirements: [
      "Original Document to be authenticated",
      "Photocopies of the document (2 sets)",
      "Valid Government Issued ID"
    ],
    processing: "Must Go To the OSAS Office",
    note: "OSAS are the one authorize for Providing Document"
  }
};

function openDocModal(docKey) {
  const data = documentInfo[docKey];
  if (!data) return;

  document.getElementById('modalIcon').innerText = data.icon;
  document.getElementById('modalTitle').innerText = data.title;
  document.getElementById('modalDescription').innerText = data.description;
  document.getElementById('modalProcessing').innerText = data.processing;
  document.getElementById('modalNote').innerText = data.note;

// Render Requirements List
const reqList = document.getElementById('modalRequirements');
reqList.innerHTML = '';

data.requirements.forEach(req => {

  if (req === "---") {
    const spacer = document.createElement('div');
    spacer.style.height = "12px";
    reqList.appendChild(spacer);
    return;
  }

  const li = document.createElement('li');
  li.innerText = req;
  reqList.appendChild(li);

});

  // Set Direct Request Link with Query Parameter
  const requestBtn = document.getElementById('modalRequestBtn');
  requestBtn.href = `request.php?doc=${encodeURIComponent(data.title)}`;

  document.getElementById('docModal').classList.add('active');
}

function closeDocModal() {
  document.getElementById('docModal').classList.remove('active');
}

// Close modal on outside click
window.addEventListener('click', (e) => {
  const modal = document.getElementById('docModal');
  if (e.target === modal) {
    closeDocModal();
  }
});

/////////////////////////////////////////////////////////
// HERO-iMAGE SLIDE
////////////////////////////////////////////////////////

document.addEventListener("DOMContentLoaded", () => {
    const slides = document.querySelectorAll(".hero-image .slide");
    let current = 0;

    function showSlide(index) {
        slides.forEach(slide => slide.classList.remove("active"));
        slides[index].classList.add("active");
    }

    // Show the first slide
    showSlide(current);

    // Change slides every 3 seconds
    setInterval(() => {
        current = (current + 1) % slides.length;
        showSlide(current);
    }, 3000);
});

//Carousel
function scrollCarousel(direction) {
    const container = document.getElementById("servicesGrid");

    const scrollAmount = container.clientWidth * 0.9;

    if (direction === 1) {
        if (container.scrollLeft + container.clientWidth >= container.scrollWidth - 5) {
            container.scrollTo({
                left: 0,
                behavior: "smooth"
            });
        } else {
            container.scrollBy({
                left: scrollAmount,
                behavior: "smooth"
            });
        }
    } else {
        if (container.scrollLeft <= 5) {
            container.scrollTo({
                left: container.scrollWidth,
                behavior: "smooth"
            });
        } else {
            container.scrollBy({
                left: -scrollAmount,
                behavior: "smooth"
            });
        }
    }
}
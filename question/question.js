let initialContents = "";
let onlyQuestions = "";

document.addEventListener("DOMContentLoaded", () => {
  initialContents = document.body.innerHTML;
  onlyQuestions = document.querySelector("article").innerHTML;

  window.addEventListener("beforeprint", clean);
  window.addEventListener("afterprint", restore);
  document.querySelector("aside button").addEventListener("click", function () {
    window.print();
  });

  const subjectButton = document.getElementById("subject");
  subjectButton.title = `Home "${window.location.origin}"`;
  subjectButton.addEventListener("click", function () {
    window.location.href = window.location.origin;
  });
});

function clean() {
  document.body.innerHTML = onlyQuestions;
  document.body.style.margin = "2.5rem 2rem";
  document.querySelector("main").style.border = "none";
}

function restore() {
  document.body.innerHTML = initialContents;
  document.body.style.margin = "0";
  document.querySelector("main").style.border = "1px solid grey";
}

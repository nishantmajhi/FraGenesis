export function renderBreadCrumbs() {
  const grade = document.querySelector('input[name="grade"]:checked').value;
  const fullMarks = document.querySelector(
    'input[name="fullMarks"]:checked'
  ).value;

  const container = document.querySelector("nav");
  container.innerHTML = "";

  const gradeCrumb = document.createElement("span");
  gradeCrumb.textContent = grade == 11 ? "Grade XI" : "Grade XII";
  container.appendChild(gradeCrumb);

  const typeCrumb = document.createElement("span");
  typeCrumb.textContent =
    fullMarks == 20
      ? "Unit Test"
      : fullMarks == 30
      ? "Internal Exam"
      : "Terminal Exam";
  container.appendChild(typeCrumb);
}

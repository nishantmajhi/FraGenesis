import { renderBreadCrumbs } from "./assets/js/breadcrumbs.js";
import { renderUnits } from "./units.js";

document.addEventListener("DOMContentLoaded", () => {
  renderBreadCrumbs();
  renderUnits();

  document.querySelectorAll('input[name="grade"]').forEach((grade) => {
    grade.addEventListener("change", () => {
      renderBreadCrumbs();
      renderUnits();
    });
  });

  document.querySelectorAll('input[name="fullMarks"]').forEach((test) => {
    test.addEventListener("change", () => {
      renderBreadCrumbs();
      renderUnits();
    });
  });

  document.getElementById("final_exam").addEventListener("click", () => {
    const grade = document.querySelector('input[name="grade"]:checked').value;
    const chapterMap = syllabus.grade[grade].chapter;
    const allTopics = Object.values(chapterMap).flatMap((chapter) =>
      chapter.topics.map((topic) => topic.id),
    );

    const params = new URLSearchParams({
      grade: grade,
      fullMarks: 50,
    });
    params.append("topics", allTopics.join(","));
    window.open(
      `${window.location.origin}/question/?${params.toString()}`,
      "_blank",
    );
  });

  document.querySelector("form").addEventListener("submit", (event) => {
    event.preventDefault();

    const grade = document.querySelector('input[name="grade"]:checked').value;
    const fullMarks = document.querySelector(
      'input[name="fullMarks"]:checked',
    ).value;

    const params =
      fullMarks == 20
        ? new URLSearchParams({
            grade: grade,
            fullMarks: fullMarks,
            mcq: document.querySelector('input[name="mcq"]').value,
            short: document.querySelector('input[name="short"]').value,
          })
        : new URLSearchParams({
            grade: grade,
            fullMarks: fullMarks,
          });

    const selectedTopics = [];
    const checkboxes = document.querySelectorAll(
      'input[name="topics[]"]:checked',
    );
    checkboxes.forEach((checkbox) => {
      selectedTopics.push(checkbox.value);
    });

    params.append("topics", selectedTopics.join(","));
    window.open(
      `${window.location.origin}/question/?${params.toString()}`,
      "_blank",
    );
  });
});

import { insertTopic, appendTopic, removeTopic } from "./topic.js";
import { insertTestCustomizer } from "./customizer.js";

export function renderUnits() {
  if (document.querySelector('input[name="fullMarks"]:checked').value == 20) {
    generateUnitSelector();
  } else {
    generateTopicSelector();
  }
}

function generateUnitSelector() {
  const chapterListItem = document.getElementById("list_of_units");
  const dropdownContent = chapterListItem.querySelector(".dropdown-content");
  dropdownContent.innerHTML = "";

  const gradeNum = document.querySelector('input[name="grade"]:checked').value;
  const chapterMap = syllabus.grade[gradeNum].chapter;
  const chapterKeys = Object.keys(chapterMap);

  const ul = document.createElement("ul");
  for (let i = 0; i < chapterKeys.length; i++) {
    const chapter = chapterMap[chapterKeys[i]];

    const li = document.createElement("li");
    const label = document.createElement("label");

    const input = document.createElement("input");
    input.type = "radio";
    input.name = "chapter";
    input.addEventListener("click", () => {
      insertTopic(chapterKeys[i]);
      insertTestCustomizer();
    });

    if (chapterKeys[i] == 1) input.checked = true;

    label.appendChild(input);
    label.append(chapter.name);

    li.appendChild(label);
    ul.appendChild(li);
  }

  dropdownContent.appendChild(ul);
  insertTopic(1);
  insertTestCustomizer();
}

function generateTopicSelector() {
  const chapterListItem = document.getElementById("list_of_units");
  const dropdownContent = chapterListItem.querySelector(".dropdown-content");
  dropdownContent.innerHTML = "";

  const gradeNum = document.querySelector('input[name="grade"]:checked').value;
  const chapterMap = syllabus.grade[gradeNum].chapter;
  const chapterKeys = Object.keys(chapterMap);

  const ul = document.createElement("ul");
  for (let i = 0; i < chapterKeys.length; i++) {
    const chapter = chapterMap[chapterKeys[i]];

    const li = document.createElement("li");
    const label = document.createElement("label");

    const input = document.createElement("input");
    input.type = "checkbox";
    input.value = chapterKeys[i];
    input.addEventListener("change", () => {
      if (input.checked) {
        appendTopic(chapterKeys[i]);
      } else {
        removeTopic(chapterKeys[i]);
      }
    });

    if (chapterKeys[i] == 1) input.checked = true;

    label.appendChild(input);
    label.append(chapter.name);

    li.appendChild(label);
    ul.appendChild(li);
  }

  dropdownContent.appendChild(ul);
  insertTopic(1);
}

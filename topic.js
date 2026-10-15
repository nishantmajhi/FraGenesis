export function insertTopic(chapterNum) {
  document.querySelector("main").innerHTML = "";
  renderTopic(chapterNum)
}

export function appendTopic(chapterNum) {
  if (document.getElementById(chapterNum)) return;
  renderTopic(chapterNum);
}

export function removeTopic(chapterNum) {
  const main = document.querySelector("main");
  const chapter = document.getElementById(chapterNum);
  main.removeChild(chapter);
}

function renderTopic(chapterNum) {
  const main = document.querySelector("main");
  const gradeNum = document.querySelector('input[name="grade"]:checked').value;
  const chapter = syllabus.grade[gradeNum].chapter[chapterNum];

  const section = document.createElement("section");
  section.id = chapterNum;
  section.style.display = "flex";
  section.style.flexDirection = "column";
  section.style.gap = "0.5rem";

  const h1 = document.createElement("h1");
  h1.textContent = chapter.name;
  section.appendChild(h1);

  chapter.topics.forEach((topic) => {
    const label = document.createElement("label");

    const action = document.createElement("span");

    const checkbox = document.createElement("input");
    checkbox.type = "checkbox";
    checkbox.name = "topics[]";
    checkbox.value = topic.id;
    checkbox.checked = true;

    action.appendChild(checkbox);
    action.append(topic.id);

    const description = document.createElement("span");
    description.textContent = topic.name;

    label.appendChild(action);
    label.appendChild(description);
    label.addEventListener("mouseover", () => {
      if (checkbox.checked == true)
        label.title = `Click to remove topic ${topic.id}`;
      else label.title = `Click to select topic ${topic.id}`;
    });

    section.appendChild(label);
  });

  main.appendChild(section);
}

export function insertTestCustomizer() {
  const main = document.querySelector("main");

  const section = document.createElement("section");
  section.style.display = "flex";
  section.style.flexDirection = "column";
  section.style.gap = "0.5rem";

  const h1 = document.createElement("h1");
  h1.textContent = "Set Marks";
  section.appendChild(h1);

  const mcqLabel = document.createElement("label");
  mcqLabel.textContent = "Multiple Choice Questions: ";

  const mcqRange = document.createElement("input");
  mcqRange.name = "mcq";
  mcqRange.type = "range";
  mcqRange.min = "0";
  mcqRange.max = "20";
  mcqRange.step = "5";
  mcqRange.value = "5";
  mcqLabel.appendChild(mcqRange);

  const mcqValue = document.createElement("span");
  mcqValue.textContent = mcqRange.value;
  mcqLabel.appendChild(mcqValue);

  mcqRange.addEventListener("input", () => {
    shortAnswerRange.value = 20 - mcqRange.value;
    mcqValue.textContent = mcqRange.value;
    shortAnswerValue.textContent = shortAnswerRange.value;
  });
  section.appendChild(mcqLabel);

  const shortAnswerLabel = document.createElement("label");
  shortAnswerLabel.textContent = "Short Answer Type Questions: ";

  const shortAnswerRange = document.createElement("input");
  shortAnswerRange.name = "short";
  shortAnswerRange.type = "range";
  shortAnswerRange.min = "0";
  shortAnswerRange.max = "20";
  shortAnswerRange.step = "5";
  shortAnswerRange.value = "15";
  shortAnswerLabel.appendChild(shortAnswerRange);

  const shortAnswerValue = document.createElement("span");
  shortAnswerValue.textContent = shortAnswerRange.value;
  shortAnswerLabel.appendChild(shortAnswerValue);

  shortAnswerRange.addEventListener("input", () => {
    mcqRange.value = 20 - shortAnswerRange.value;
    mcqValue.textContent = mcqRange.value;
    shortAnswerValue.textContent = shortAnswerRange.value;
  });
  section.appendChild(shortAnswerLabel);

  main.appendChild(section);
}

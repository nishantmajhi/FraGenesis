document.addEventListener("DOMContentLoaded", () => {
  document.querySelectorAll(".dropdown").forEach((dropdown) => {
    const button = dropdown.querySelector("button");
    const dialog = dropdown.querySelector("dialog");

    let isHovered = false;
    let isInsideDialog = false;

    function closeAllDropdowns() {
      document.querySelectorAll(".dropdown dialog").forEach((d) => {
        d.close();
        d.classList.remove("clicked");
      });
    }

    function checkCloseDropdown() {
      if (!isHovered && !isInsideDialog && dialog.open) {
        dialog.close();
        dialog.classList.remove("clicked");
      }
    }

    dropdown.addEventListener("focusin", () => {
      isHovered = true;
      closeAllDropdowns();
      dialog.show();
    });

    dropdown.addEventListener("focusout", () => {
      isHovered = false;
      checkCloseDropdown();
    });

    dropdown.addEventListener("mouseenter", () => {
      isHovered = true;
      closeAllDropdowns();
      dialog.show();
    });

    dropdown.addEventListener("mouseleave", () => {
      isHovered = false;
      checkCloseDropdown();
    });

    dialog.addEventListener("mouseenter", () => {
      isInsideDialog = true;
    });

    dialog.addEventListener("mouseleave", () => {
      isInsideDialog = false;
      checkCloseDropdown();
    });

    button.addEventListener("click", () => {
      if (dialog.open && isHovered) {
        dialog.classList.add("clicked");
      } else if (dialog.open) {
        dialog.close();
        dialog.classList.remove("clicked");
        isHovered = false;
      } else {
        closeAllDropdowns();
        dialog.show();
      }
    });

    document.addEventListener("click", (event) => {
      if (!dropdown.contains(event.target)) {
        dialog.close();
        dialog.classList.remove("clicked");
        isHovered = false;
      }
    });
  });
});

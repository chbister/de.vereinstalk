document.querySelectorAll('#year').forEach((element) => {
  element.textContent = new Date().getFullYear();
});

import { useEffect } from "react";

let observer = null;

function getObserver() {
  if (observer) return observer;

  observer = new IntersectionObserver(
    (entries) => {
      const vh = window.innerHeight || document.documentElement.clientHeight;
      entries.forEach((entry) => {
        const rect = entry.boundingClientRect;
        const fullyOut = rect.bottom <= 0 || rect.top >= vh;
        if (entry.isIntersecting) {
          entry.target.classList.add("is-visible");
        } else if (fullyOut) {
          entry.target.classList.remove("is-visible");
        }
      });
    },
    { threshold: 0.15, rootMargin: "0px 0px -40px 0px" }
  );

  return observer;
}

export default function useScrollReveal() {
  useEffect(() => {
    const elements = document.querySelectorAll("[data-reveal]");
    if (!elements.length) return undefined;

    const io = getObserver();
    elements.forEach((element) => io.observe(element));

    return () => {
      elements.forEach((element) => io.unobserve(element));
    };
  }, []);
}

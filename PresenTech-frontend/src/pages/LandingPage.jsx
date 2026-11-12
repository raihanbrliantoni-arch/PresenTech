import { useEffect } from "react";
import Navbar from "../components/Navbar";
import Hero from "../components/Hero";
import About from "../components/About";
import Features from "../components/Features";
import HowItWorks from "../components/HowItWorks";
import Users from "../components/Users";
import Footer from "../components/Footer";

// Animasi masuk halus saat bagian halaman terlihat.
// Jika IntersectionObserver tidak tersedia, semua langsung tampil.
function useScrollReveal() {
  useEffect(() => {
    const nodes = Array.from(document.querySelectorAll("[data-reveal]"));

    if (!nodes.length) return undefined;

    if (!("IntersectionObserver" in window)) {
      nodes.forEach((node) => node.classList.add("is-visible"));
      return undefined;
    }

    // Jeda bertahap untuk elemen yang bersaudara (kartu fitur, langkah).
    const groups = new Map();
    nodes.forEach((node) => {
      const parent = node.parentElement;
      const list = groups.get(parent) || [];
      list.push(node);
      groups.set(parent, list);
    });
    groups.forEach((list) => {
      list.forEach((node, index) => {
        node.style.setProperty("--reveal-delay", `${Math.min(index * 55, 275)}ms`);
      });
    });

    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            entry.target.classList.add("is-visible");
            observer.unobserve(entry.target);
          }
        });
      },
      { rootMargin: "0px 0px -6% 0px", threshold: 0.08 }
    );

    nodes.forEach((node) => observer.observe(node));
    return () => observer.disconnect();
  }, []);
}

function LandingPage({ onNavigate, darkMode, setDarkMode }) {
  useScrollReveal();

  return (
    <>
      {/* ==================================================
          NAVBAR
      ================================================== */}

      <Navbar
        onNavigate={onNavigate}
        darkMode={darkMode}
        setDarkMode={setDarkMode}
      />

      {/* ==================================================
          MAIN
      ================================================== */}

      <main>
        <Hero />

        <About />

        <Features />

        <HowItWorks />

        <Users />
      </main>

      {/* ==================================================
          FOOTER
      ================================================== */}

      <Footer onNavigate={onNavigate} darkMode={darkMode} />
    </>
  );
}

export default LandingPage;

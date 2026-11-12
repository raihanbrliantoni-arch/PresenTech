import React from "react";
import ReactDOM from "react-dom/client";

import App from "./App.jsx";

import "./styles/App.css";
import "./styles/theme.css";
import "./styles/landing.css";
import "./styles/auth.css";
import "./styles/dashboards.css";
import "./styles/redesign.css";

ReactDOM.createRoot(
  document.getElementById("root")
).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>
);
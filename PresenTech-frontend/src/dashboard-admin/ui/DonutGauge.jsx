/**
 * Gauge/donut persentase. Dipakai untuk ringkasan kehadiran hari ini.
 * Menggunakan SVG + stroke-dasharray agar ringan dan tajam di semua layar.
 */
export default function DonutGauge({ value = 0, title, caption, size = 148, stroke = 13, tone = "emerald" }) {
  const hasValue = value !== null && value !== undefined && Number.isFinite(Number(value));
  const percent = hasValue ? Math.max(0, Math.min(100, Math.round(Number(value)))) : 0;
  const radius = (size - stroke) / 2;
  const circumference = 2 * Math.PI * radius;
  const filled = (percent / 100) * circumference;

  return (
    <div className={`donut-gauge tone-${tone}`} style={{ width: size }}>
      <svg width={size} height={size} role="img" aria-label={`${title || "Persentase"}: ${hasValue ? `${percent}%` : "memuat"}`}>
        <circle className="donut-track" cx={size / 2} cy={size / 2} r={radius} fill="none" strokeWidth={stroke} />
        <circle
          className="donut-value"
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          strokeWidth={stroke}
          strokeLinecap="round"
          strokeDasharray={`${filled} ${circumference - filled}`}
          transform={`rotate(-90 ${size / 2} ${size / 2})`}
        />
      </svg>

      <div className="donut-center">
        <strong>{hasValue ? `${percent}%` : "—"}</strong>
        {caption && <span>{caption}</span>}
      </div>
    </div>
  );
}
/**
 * Grafik garis tren 7 hari (hadir + terlambat).
 * Data: [{ label, hadir, terlambat }]
 */
export default function TrendChart({ data = [], height = 190 }) {
  const width = 720;
  const padding = { top: 16, right: 16, bottom: 30, left: 36 };
  const innerWidth = width - padding.left - padding.right;
  const innerHeight = height - padding.top - padding.bottom;

  const points = data.map((item) => ({
    label: item.label,
    value: Number(item.hadir || 0) + Number(item.terlambat || 0),
    hadir: Number(item.hadir || 0),
  }));

  const maxValue = Math.max(1, ...points.map((point) => point.value));
  const stepX = points.length > 1 ? innerWidth / (points.length - 1) : 0;

  const coords = points.map((point, index) => ({
    ...point,
    x: padding.left + index * stepX,
    y: padding.top + innerHeight - (point.value / maxValue) * innerHeight,
  }));

  const linePath = coords.map((point, index) => `${index === 0 ? "M" : "L"}${point.x.toFixed(1)},${point.y.toFixed(1)}`).join(" ");
  const areaPath = coords.length
    ? `${linePath} L${coords[coords.length - 1].x.toFixed(1)},${(padding.top + innerHeight).toFixed(1)} L${coords[0].x.toFixed(1)},${(padding.top + innerHeight).toFixed(1)} Z`
    : "";

  const gridValues = [0, 0.5, 1].map((ratio) => ({
    y: padding.top + innerHeight - ratio * innerHeight,
    value: Math.round(maxValue * ratio),
  }));

  return (
    <div>
      <svg className="trend-chart" viewBox={`0 0 ${width} ${height}`} role="img" aria-label="Tren kehadiran tujuh hari terakhir">
        {gridValues.map((grid) => (
          <g key={grid.y}>
            <line className="trend-grid" x1={padding.left} x2={width - padding.right} y1={grid.y} y2={grid.y} />
            <text x={padding.left - 8} y={grid.y + 3.5} textAnchor="end">{grid.value}</text>
          </g>
        ))}

        {areaPath && <path className="trend-area" d={areaPath} />}
        {linePath && <path className="trend-line" d={linePath} />}

        {coords.map((point) => (
          <g key={`${point.label}-${point.x}`}>
            <circle className="trend-dot" cx={point.x} cy={point.y} r={4} />
            <text x={point.x} y={height - 8} textAnchor="middle">{point.label}</text>
          </g>
        ))}
      </svg>

      <div className="trend-legend">
        <span><i />Hadir + terlambat per hari</span>
        <span><i className="muted" />Maksimum {maxValue}</span>
      </div>
    </div>
  );
}
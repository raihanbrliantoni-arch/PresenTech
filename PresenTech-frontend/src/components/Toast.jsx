// Toast global PresenTech: pengganti window.alert dengan
// desain yang sama di seluruh dashboard. Gunakan `toast` dari lib/toast.
import { useEffect, useState } from "react";
import { CheckCircle2, Info, TriangleAlert, X } from "lucide-react";
import { mountToasts } from "../lib/toast";

const ICONS = { success: CheckCircle2, error: TriangleAlert, info: Info };

export default function ToastHost() {
  const [items, setItems] = useState([]);

  useEffect(
    () =>
      mountToasts((item) => {
        setItems((old) => [...old, item]);
        setTimeout(() => setItems((old) => old.filter((x) => x.id !== item.id)), item.duration);
      }),
    []
  );

  if (!items.length) return null;
  return (
    <div className="toast-host" role="status" aria-live="polite">
      {items.map((item) => {
        const Icon = ICONS[item.type] || Info;
        return (
          <div key={item.id} className={`toast toast-${item.type}`}>
            <Icon size={16} />
            <span>{item.message}</span>
            <button onClick={() => setItems((old) => old.filter((x) => x.id !== item.id))} aria-label="Tutup notifikasi"><X size={13} /></button>
          </div>
        );
      })}
    </div>
  );
}

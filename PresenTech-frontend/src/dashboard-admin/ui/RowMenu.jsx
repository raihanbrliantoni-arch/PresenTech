import { useEffect, useLayoutEffect, useRef, useState } from "react";
import { MoreVertical } from "lucide-react";

/**
 * Menu tiga titik di ujung baris tabel. Panel memakai position:fixed agar
 * tidak terpotong oleh container tabel yang bisa di-scroll, dan otomatis
 * membuka ke atas bila ruang di bawah tidak cukup.
 * Item: [{ label, icon, onClick, danger }]
 */
export default function RowMenu({ items = [], label = "Aksi baris" }) {
  const [open, setOpen] = useState(false);
  const [position, setPosition] = useState(null);
  const containerRef = useRef(null);
  const listRef = useRef(null);

  useEffect(() => {
    if (!open) return undefined;

    const onPointerDown = (event) => {
      if (containerRef.current?.contains(event.target) || listRef.current?.contains(event.target)) return;
      setOpen(false);
    };

    const onKeyDown = (event) => {
      if (event.key === "Escape") setOpen(false);
    };

    document.addEventListener("mousedown", onPointerDown);
    document.addEventListener("keydown", onKeyDown);

    return () => {
      document.removeEventListener("mousedown", onPointerDown);
      document.removeEventListener("keydown", onKeyDown);
    };
  }, [open]);

  useLayoutEffect(() => {
    if (!open) {
      setPosition(null);
      return undefined;
    }

    const update = () => {
      const trigger = containerRef.current?.querySelector(".row-menu-trigger");
      const list = listRef.current;

      if (!trigger || !list) return;

      const rect = trigger.getBoundingClientRect();
      const menuWidth = list.offsetWidth || 184;
      const menuHeight = list.offsetHeight || 220;
      const spaceBelow = window.innerHeight - rect.bottom;
      const openUp = spaceBelow < menuHeight + 12 && rect.top > menuHeight + 12;

      const left = Math.max(8, Math.min(rect.right - menuWidth, window.innerWidth - menuWidth - 8));
      const top = openUp ? Math.max(8, rect.top - menuHeight - 8) : rect.bottom + 8;

      setPosition({ top, left, width: menuWidth });
    };

    update();

    window.addEventListener("resize", update);
    window.addEventListener("scroll", update, true);

    return () => {
      window.removeEventListener("resize", update);
      window.removeEventListener("scroll", update, true);
    };
  }, [open]);

  if (!items.length) return null;

  return (
    <div className="row-menu" ref={containerRef}>
      <button
        type="button"
        className="row-menu-trigger"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={label}
        onClick={() => setOpen((value) => !value)}
      >
        <MoreVertical size={17} />
      </button>

      {open && (
        <div
          ref={listRef}
          className="row-menu-list"
          role="menu"
          style={{
            position: "fixed",
            top: position ? position.top : 0,
            left: position ? position.left : 0,
            width: position ? position.width : 184,
            visibility: position ? "visible" : "hidden",
          }}
        >
          {items.map((item) => (
            <button
              key={item.label}
              type="button"
              role="menuitem"
              className={item.danger ? "danger" : ""}
              onClick={() => {
                setOpen(false);
                item.onClick?.();
              }}
            >
              {item.icon}
              <span>{item.label}</span>
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
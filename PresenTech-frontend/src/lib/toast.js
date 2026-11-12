const listeners = new Set();
let toastSeq = 0;

export const toast = {
  show(message, type = "info", duration = 4200) {
    const item = { id: ++toastSeq, message, type, duration };
    listeners.forEach((listener) => listener(item));
  },
  success(message, duration) {
    toast.show(message, "success", duration);
  },
  error(message, duration) {
    toast.show(message, "error", duration);
  },
};

export function mountToasts(listener) {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

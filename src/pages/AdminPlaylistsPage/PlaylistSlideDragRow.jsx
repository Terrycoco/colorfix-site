import { cloneElement, createContext, useCallback, useContext } from "react";
import { useDraggable, useDroppable } from "@dnd-kit/core";
import { GripVertical } from "lucide-react";
import "./playlist-slide-drag.css";

const DragHandleContext = createContext(null);

export function PlaylistSlideDragHandle() {
  const { attributes, listeners, setActivatorNodeRef, disabled, index, onMove } = useContext(DragHandleContext);
  return (
    <button
      ref={setActivatorNodeRef}
      type="button"
      className="playlist-slide-drag-handle"
      {...attributes}
      {...listeners}
      disabled={disabled}
      title="Drag to reorder slide"
      aria-label={`Reorder slide ${index + 1}`}
      onClick={(event) => event.stopPropagation()}
      onDoubleClick={(event) => event.stopPropagation()}
      onKeyDown={(event) => {
        if (disabled || !["ArrowUp", "ArrowDown"].includes(event.key)) return;
        event.preventDefault();
        event.stopPropagation();
        onMove(index, event.key === "ArrowUp" ? index - 1 : index + 1);
      }}
    >
      <GripVertical size={18} aria-hidden="true" />
    </button>
  );
}

export default function PlaylistSlideDragRow({ row, item, index, disabled, onMove }) {
  const { attributes, listeners, setNodeRef: setDragRef, setActivatorNodeRef, transform, isDragging } = useDraggable({
    id: item._clientKey, disabled, data: { index },
  });
  const { setNodeRef: setDropRef, isOver, active } = useDroppable({ id: item._clientKey, disabled });
  const setNodeRef = useCallback((node) => {
    setDragRef(node);
    setDropRef(node);
  }, [setDragRef, setDropRef]);
  const dropClass = isOver && !isDragging
    ? (active?.data.current?.index < index ? " is-drop-after" : " is-drop-before") : "";

  return (
    <DragHandleContext.Provider value={{ attributes, listeners, setActivatorNodeRef, disabled, index, onMove }}>
      {cloneElement(row, {
        ref: setNodeRef,
        className: `${row.props.className} playlist-slide-drag-row${isDragging ? " is-dragging" : ""}${dropClass}`,
        style: transform ? { transform: `translate3d(0, ${transform.y}px, 0)`, position: "relative", zIndex: 1 } : undefined,
      })}
    </DragHandleContext.Provider>
  );
}

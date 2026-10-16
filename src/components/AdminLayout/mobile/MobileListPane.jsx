import { Children, isValidElement } from "react";
import AdminObjectList from "../AdminObjectList";
import AdminObjectListItem from "../AdminObjectListItem";
import MobileSelect from "./MobileSelect";
import "./MobileListPane.css";

/** Mobile alternative to AdminListPane for standard object-selection lists. */
export default function MobileListPane({
  title,
  actions = null,
  children,
  className = "",
  searchPlaceholder = "Search…",
}) {
  const nodes = Children.toArray(children);
  const list = nodes.find((node) => isValidElement(node) && node.type === AdminObjectList);
  const items = list ? Children.toArray(list.props.children).filter((node) =>
    isValidElement(node) && node.type === AdminObjectListItem
  ) : [];
  const selectedIndex = items.findIndex((item) => Boolean(item.props.selected));
  const options = items.map((item, index) => ({
    value: String(index),
    label: String(item.props.title ?? `Item ${index + 1}`),
    detail: item.props.id != null && item.props.id !== "" ? `#${item.props.id}` : "",
    disabled: !item.props.onSelect,
  }));

  return (
    <section className={`admin-mobile-list-pane ${className}`.trim()}>
      <MobileSelect
        title={typeof title === "string" ? title : "Select item"}
        label={title}
        options={options}
        value={selectedIndex < 0 ? "" : String(selectedIndex)}
        onChange={(index) => items[Number(index)]?.props.onSelect?.()}
        searchable={items.length > 8}
        searchPlaceholder={searchPlaceholder}
        placeholder="Choose…"
      />
      {/* Do not discard loading/error notices supplied by a calling page. */}
      {nodes.filter((node) => node !== list)}
      {/* Page-owned buttons are intentionally omitted from compact picker header. */}
    </section>
  );
}

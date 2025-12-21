import { Outlet } from "react-router-dom";
import "./standalone-layout.css";

export default function StandAloneLayout() {
  return (
    <div className="standalone-layout">
      <Outlet />
    </div>
  );
}

import { useState, useRef } from "react";
import FragmentView from "./FragmentView";
import QueryView from "./QueryView";

export default function SQLPage() {
  const [mode, setMode] = useState("query");
  const [builtQuery, setBuiltQuery] = useState("");
  const [queryDirty, setQueryDirty] = useState(false);

  const fragmentViewRef = useRef();

  const toggleView = () => {
    if (mode === "fragment") {
      const assembled = fragmentViewRef.current?.getQuery();
      setBuiltQuery(assembled);
      setMode("query");
    } else {
     // if (queryDirty && !confirm("Discard manual edits and return to built version?")) return;
      setMode("fragment");
    }
  };



  return (
    <div className="h-screen min-hbg-gray-200 max-w-screen-xl mx-auto">
      <div className="pl-6 flex justify-start items-center mt-2 mb-2 gap-4">
        <h3 className="text-lg font-bold">SQL Control Room</h3>
        <button
          className="bg-gray-800 text-white px-4 py-2 rounded"
          onClick={toggleView}
        >
          {mode === "fragment" ? "Switch to Query View" : "Switch to Fragment View"}
        </button>
      </div>

      {mode === "fragment" ? (
        <FragmentView ref={fragmentViewRef}  />
      ) : (
        <QueryView
          query={builtQuery}
          onQueryChange={setBuiltQuery}
          onDirty={setQueryDirty}
        />
      )}
    </div>
  );
}

import {
  forwardRef,
  useImperativeHandle,
  useState,
  useEffect
} from "react";
import FragmentRow from "./FragmentRow";
import { API_FOLDER } from "@helpers/config";

const FragmentView = forwardRef((props, ref) => {
  const slots = [
    { label: "SELECT", type: "select" },
    { label: "FROM", type: "from" },
    { label: "WHERE", type: "where" },
    { label: "ORDER BY", type: "orderby" },
    { label: "LIMIT", type: "limit" },
  ];

  const [savedFrags, setSavedFrags] = useState([]); //list from db
  const [fragmentData, setFragmentData] = useState({});  //

  useEffect(() => {
     console.log("Fetching fragments from:", `${API_FOLDER}/get-fragments.php`);
   fetch(`${API_FOLDER}/get-fragments.php?t=${Date.now()}`)
      .then((res) => res.json())
      .then((data) => {
          console.log("RAW data received:", data);
        if (Array.isArray(data)) {
         
          setSavedFrags(data);

          const initialData = {};
          slots.forEach((slot) => {
            console.log(`Matching slot type: ${slot.type}`);
            console.log("Available fragment types in data:", data.map(d => d.type));
            const match = data.find((item) => item.type === slot.type);
            initialData[slot.type] = {
              selectedId: match ? match.id : "new",
              text: match ? match.text : "",
              name: match ? match.name : "",
            };
          });
          setFragmentData(initialData);
        } else {
          console.error("Unexpected response:", data);
        }
      })
      .catch((err) => {
        console.error("Error fetching fragments:", err);
      });
  }, []);

  useImperativeHandle(ref, () => ({
    getQuery: () => {
      return slots
        .map(({ type, label }) => {
          const text = fragmentData[type]?.text?.trim();
          return text ? ` ${text}` : "";
        })
        .filter(Boolean)
        .join("\n");
    },
  }));

  async function handleSaveFragment(slotType) {
    const { selectedId, text, name } = fragmentData[slotType];
    const isNew = selectedId === "new";

    if (!name.trim() || !text.trim()) {
      alert("Name and SQL text are required.");
      return;
    }

    const payload = {
      id: isNew ? null : selectedId,
      name,
      text,
      type: slotType,  // include this!
    };

    try {
      const response = await fetch(`${API_FOLDER}/upsert-fragment.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });

      const result = await response.json();
      if (!response.ok) throw new Error(result.error || "Unknown error");

      const newFrag = {
        id: result.id,
        name: result.name,
        text: result.text,
        type: result.type
      };

      setSavedFrags((prev) => {
        const existingIndex = prev.findIndex((f) => f.id === newFrag.id);
        if (existingIndex !== -1) {
          const updated = [...prev];
          updated[existingIndex] = newFrag;
          return updated;
        }
        return [...prev, newFrag];
      });

      setFragmentData((prev) => ({
        ...prev,
        [slotType]: {
          selectedId: result.id,
          text: result.text,
          name: result.name,
        },
      }));

      alert("Fragment saved successfully!");
    } catch (err) {
      console.error("Save error:", err);
      alert("Failed to save fragment.");
    }
  }

  return (
    <div ref={ref}>
      <div className="pl-10 max-w-3xl">
        <h4 className="text-md font-semibold mb-6">Fragments</h4>
        {slots.map((slot) => {
          const current = fragmentData[slot.type] || {};
          const selectedFrag = savedFrags.find(
            (opt) => opt.id === current.selectedId
          );

          return (
            <FragmentRow
              key={slot.type}
              label={slot.label}
              options={savedFrags.filter((f) => f.type === slot.type)} 
              selectedId={current.selectedId || "new"}
              text={current.text || ""}
              name={current.name || selectedFrag?.name || ""}
              onSelectChange={(e) => {
                const selectedId = e.target.value;
               const fragMatch = savedFrags.find((opt) => opt.id == selectedId);
                console.log("Selected slot type:", slot.type);
                console.log("Fragment text:", fragMatch?.text);
                setFragmentData((prev) => ({
                  ...prev,
                  [slot.type]: {
                    selectedId,
                    text: fragMatch?.text || "",
                    name: fragMatch?.name || "",
                  },
                }));
              }}
              onTextChange={(e) => {
                const newText = e.target.value;
                setFragmentData((prev) => ({
                  ...prev,
                  [slot.type]: {
                    ...prev[slot.type],
                    text: newText,
                  },
                }));
              }}
              onNameChange={(e) => {
                const newName = e.target.value;
                setFragmentData((prev) => ({
                  ...prev,
                  [slot.type]: {
                    ...prev[slot.type],
                    name: newName,
                  },
                }));
              }}
              onSave={() => handleSaveFragment(slot.type)}
            />
          );
        })}
      </div>
    </div>
  );
});

export default FragmentView;

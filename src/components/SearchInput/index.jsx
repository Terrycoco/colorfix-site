import {useState, useRef, useEffect} from 'react';


export default function SearchInput({ onSearch }) {
  const [query, setQuery] = useState("");
  const inputRef = useRef(null);
  const justSearched = useRef(false);

  const handleSubmit = (e) => {
    e.preventDefault();
    const trimmed = query.trim();
    if (trimmed) {
      justSearched.current = true;
      onSearch(trimmed);
    }
  };

  useEffect(() => {
    if (justSearched.current && inputRef.current) {
      inputRef.current.select();
      justSearched.current = false;

      // iOS zoom reset trick
      inputRef.current.blur();
      setTimeout(() => inputRef.current.focus(), 50);
    }
  }, [query]);

  return (
    <form onSubmit={handleSubmit} className="flex gap-2 w-full shrink-0">
      <input
        ref={inputRef}
        type="text"
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        placeholder="Search a color name or code"
        className="flex-grow min-w-0 p-3 text-[16px] border border-gray-300 rounded-xl shadow-sm"
        autoFocus
        onFocus={(e) => e.target.select()}
      />
      <button
        type="submit"
        className="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-xl"
      >
        Search
      </button>
    </form>
  );
}

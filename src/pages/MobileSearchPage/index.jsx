const serverRoot = 'https://colorfix.terrymarr.com/api';
import { useState } from "react";
import MobileLayout from "@layout/MobileLayout";
import SearchInput from "@components/SearchInput";
import SearchResultsList from '@components/SearchResultsList';

export default function MobileSearchPage() {
  const [lastQuery, setLastQuery] = useState("");
  const [results, setResults] = useState([]);
  const [limited, setLimited] = useState(false);

  const handleSearch = async (query) => {
    try {
        const res = await fetch(`${serverRoot}/search-colors-fuzzy.php?q=${encodeURIComponent(query)}`);
        const data = await res.json();
        console.log('results:', data);
        setResults(data.results);
        setLimited(data.too_many);
    } catch (err) {
        console.error("Search error:", err);
        setResults([]);
        setLimited(false);
    }
    };


  return (
    <MobileLayout>
      <h3 className="text-xl font-semibold text-center mb-6">
        Find a Paint Color
      </h3>
      <SearchInput onSearch={handleSearch} />
      {limited && (
            <p className="text-sm text-gray-500 mt-2 px-2">
                Too many results. Try narrowing your search.
            </p>
            )}
      <SearchResultsList results={results} />
    </MobileLayout>
  );
}

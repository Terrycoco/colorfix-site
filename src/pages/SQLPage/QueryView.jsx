import { useEffect, useState } from "react";
import { useAppState } from '@context/appStateContext';
import {API_FOLDER} from '@helpers/config';
import QueryResultGrid from './QueryResultGrid';
import QueryInfoPanel from './QueryInfoPanel';
import ResponsiveRow from '@layout/ResponsiveRow';
import Column from '@layout/Column';

const initialEmptyQuery ={
  query_id: null,
  type: '',
  item_type: '',
  name: '',
  display: '',
  active: 1,
  sort_order: 0,
  query: '',
  notes: '',
  description: '',
  parent_id: '',
  pinnable: 0,
  has_header: 0,
  header_title: '',
  header_subtitle: '',
  header_content: ''
};
 
export default function QueryView({ query, onQueryChange, onDirty }) {
  const [text, setText] = useState(query);
  const [results, setResults] = useState(null);
  const [error, setError] = useState(null);
  const [queryInfo, setQueryInfo] = useState(initialEmptyQuery);
  const [savedQueries, setSavedQueries] = useState([]);
  const { setMessage } = useAppState();

  //USE EFFECTS
  useEffect(() => {
    fetchSavedQueries();
  }, []);

  useEffect(() => {
    setText(query);
    setError(null);
    setResults(null);
  }, [query]);



  const fetchSavedQueries = async () => {
    try {
      const res = await fetch(`${API_FOLDER}/get-all-queries.php`);
   
      const json = await res.json();
      console.log("Loaded queries:", json);  // <-- key line
      setSavedQueries(json.data);
    } catch (err) {
      console.error("Error loading saved queries:", err);
    }
  };

  const handleChange = (e) => {
      setText(e.target.value);
      onQueryChange(e.target.value);
      onDirty(true);
  };

  const handleSaveQuery = async () => {
      if (!queryInfo.name || !text.trim()) {
        setMessage("Name and sql are required before saving.");
        return;
      }

        const payload = {
          ...queryInfo,
          query: text
        };

      try {
        const response = await fetch(`${API_FOLDER}/upsert-query.php`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify(payload)
        });

        const result = await response.json();

        if (!response.ok) {
          console.error("Save error:", result.error);
          setMessage("Error saving query. See console.");
          return;
        }

        setMessage("Query saved successfully!");

        if (result.query_id && !queryInfo.query_id) {
          // If it was just created, update queryInfo with the new ID
          setQueryInfo({ ...queryInfo, query_id: result.query_id });
        }

        // Optional: refresh the savedQueries list here if you want
        fetchSavedQueries();

      } catch (err) {
        console.error("Request failed:", err);
        setMessage("Network or server error.");
      }
    };


  const handleSelectQuery = (e) => {
    const selectedId = parseInt(e.target.value);
    const selected = savedQueries.find(q => q.query_id === selectedId);
    if (selected) {
      setQueryInfo(selected);
      setText(selected.query);  // ← the reverse flow you're asking for
    } else {
      setQueryInfo(initialEmptyQuery);
      setText('');
    }
  };

  //done
  const testQuery = async () => {
    setError(null);
    setResults(null);
    try {
      console.log('raw sql:', text);
      const res = await fetch(`${API_FOLDER}/test-query.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ sql: text })
      });
      const data = await res.json();
      console.log('data:', data);
      if (data.error) setError(data.error);
      else setResults(data.rows);
    } catch (err) {
      setError("Unexpected error running query.");
    }
  };


  //test run
  const runQuery = async () => {
    if (!queryInfo.query_id) {
      setMessage("Please select a saved query to run.");
      return;
    }

    try {
      const response = await fetch(`${API_FOLDER}/run-query.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({ query_id: queryInfo.query_id })
      });

      const json = await response.json();

      if (!response.ok) {
        console.error("Run error:", json.error);
        setMessage("Failed to run query. See console.");
        return;
      }

      console.log("json result:", json);  // or set to state to display
      setMessage(`Query ran successfully (${json.rowCount} rows returned).`);
      setResults(json.results) 

    } catch (err) {
      console.error("Request failed:", err);
      setMessage("Network or server error.");
    }
  };

  const handleClear = () => {
    setQueryInfo(initialEmptyQuery);
    setText('');
    setResults(null);
    setError(null);
  };


  return (

  <div id="QueryView" className="w-full">
  
      <ResponsiveRow>
        <Column width="1/2">
        <div className="w-full flex justify-between gap-4">
        
            <select onChange={handleSelectQuery}
              className=" mt-0 bg-white mb-2 self-start h-auto"
            >
              <option value="">-- choose a query --</option>
              {savedQueries.map(q => (
                <option key={q.query_id} value={q.query_id}>{q.display || q.name}</option>
              ))}
            </select>
   
          
         <div className="p-2 flex-1 border">
            <ul className="text-xs">To call another query you must return:
             <li>'search' as item_type,  --how it will display</li>
             <li>1 as on_click_query,  --what will run when clicked</li> 
             <li>JSON_OBJECT('code', code) as on_click_params --if expected</li> </ul>
         </div>
      </div>



            <div className="w-full">

            <h4 className="mt-0 text-sm font-semibold mb-2">Query SQL</h4>
                  <textarea
                      value={text}
                      onChange={handleChange}
                      rows={10}
                      className="bg-white w-full h-32 p-2 border border-gray-300 rounded resize"
                    />
            </div>

          {/* Button row */}
          <div className="flex gap-4 justify-start items-center mb-1">
            <button
              className=" text-white px-4 py-2 rounded"
              onClick={testQuery}
            >
              Test Raw
            </button>

            <button
              className=" text-white px-4 py-2 rounded"
              onClick={handleSaveQuery}
            >
              Save Query
            </button>
            <button
              className=" text-white px-4 py-2 rounded"
              onClick={runQuery}
            >
              Run Saved Query
            </button>
            <button
              className="text-white px-4 py-2 rounded bg-gray-500 hover:bg-gray-600"
              onClick={handleClear}
            >
              Clear / New
            </button>
      
            {results && results.length > 0 && (
            <div className="pl-2 text-sm">Results: {results.length}</div>
          )}
          </div>

   {error && (
        <div className="text-red-600 mt-2">
          <strong>Error:</strong> {error}
        </div>
      )}

   
      {results && results.length > 0 && (
        <QueryResultGrid rows={results} />
      )}

      {results && results.length === 0 && (
        <div className="text-gray-500 mt-2">Query ran successfully — no results.</div>
      )}

        </Column>
      

        {/* QueryInfo Quadrant */}
        <Column width="1/2">
         {savedQueries && savedQueries.length > 0 && (
            <QueryInfoPanel queryOptions={savedQueries} queryInfo={queryInfo} setQueryInfo={setQueryInfo} />
         )}
          </Column>
          

    </ResponsiveRow>


    {/* RESULTS GRID */}
    <ResponsiveRow>

   
 

    </ResponsiveRow>
    
    </div>
  );
}

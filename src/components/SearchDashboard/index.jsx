import {useState, useEffect} from 'react';
import {useAppState} from '@context/AppStateContext';
import {fetchColorsByCategory, fetchColors} from '@data/fetchColors';
import CategoryDropdown from '@components/CategoryDropdown';
import SortButtons from '@components/SortButtons';

function Dashboard() {

    const [isExpanded, setIsExpanded] = useState(false);
    const [selectedCategory, setSelectedCategory] = useState('all');
    const {setColors, selectedWorkingColor, showComplementsFilter, setShowComplementsFilter} = useAppState();
    

    useEffect(() => {
        fetchColors(setColors);
    }, []);


    useEffect(() => {
          selectedCategory === 'all'
            ? fetchColors( setColors)
            : fetchColorsByCategory(selectedCategory, setColors);
      }, [selectedCategory]);
    
    function handleCategoryChange(newVal) {
      setSelectedCategory(newVal);
    }

     const toggleComplementFilter = () => {
    if (selectedWorkingColor) {
      setShowComplementsFilter(prev => !prev);
    }
  };


    return (
        <div className="dashboard-container">
            <div className="dashboard-header">
                <CategoryDropdown onSelect={handleCategoryChange} />
                   <button
                      className={`complement-toggle-btn ${showComplementsFilter ? 'active' : ''}`}
                      onClick={toggleComplementFilter}
                      disabled={!selectedWorkingColor}
                  >
                      {showComplementsFilter ? 'Showing Complements' : 'Show Complements'}
                </button>
               <button
                        className="toggle-button"
                        onClick={() => setIsExpanded(!isExpanded)}
                        aria-label="Toggle filters"
                    >
                       <span className='toggle-label'>Filter/Sort</span> {isExpanded ? '▲' : '▼'} 
             </button>
        </div>
        
          {isExpanded && (
        <div className="dashboard-expanded">
            {/* Other filters, sort dropdowns, checkboxes, hue sliders etc. */}
            <SortButtons />
        </div>
            )}


      
  </div>
    )
}

export default Dashboard;
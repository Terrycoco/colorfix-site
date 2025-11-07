import React, { useState, useEffect, useRef , useMemo} from 'react';
import {useAppState} from '@context/appStateContext';
import * as d3 from 'd3';
import './wheel.css';
const serverRoot = 'https://colorfix.terrymarr.com/api';
import fetchRGBLookup from '@data/fetchRGBLookup';

function isEven(number) {
  return number % 2 === 0; // Returns true if the remainder is 0 (even), false otherwise (odd)
}


/*base color wheel
const appendColoredArc = (g, width, hueStart, hueEnd,  fillHex) => {
  //console.log('hueStart:', hueStart, 'end: ', hueEnd, 'Fill:', fillHex);
    
    //change hue angles to radians
    const startAngle = hueStart * Math.PI/180;
    const endAngle = hueEnd * Math.PI/180;
    //const innerRadius = width * .25;
    //const outerRadius = width * .375;
    const innerRadius = width * .300;
    const outerRadius = width * .475;

    //console.log('inner:', innerRadius, "outer: ", outerRadius)

    //creates new path for arc
    const arcGenerator = d3.arc()
      .innerRadius(innerRadius)
      .outerRadius(outerRadius)
      .startAngle(startAngle)
      .endAngle(endAngle);

    //appends path inside of group
    g.append('path')
      .attr('d', arcGenerator())
      .attr('fill', fillHex);

 
}

*/


//for real values
const appendArcToGroup = (g, width, cat, i) => {
  if (!cat) return;
    //change hue angles to radians
    const startAngle = cat.hue_start * Math.PI/180;
    const endAngle = cat.hue_end * Math.PI/180;
    const innerRadius = !isEven(i) ? width * .300 : width * .388;
    const outerRadius = !isEven(i) ? width * .388 : width * .476;
 
    const fontSize = `${width * .036}px`;

    const dyAdjust = width * .04;

    //creates new path for outer label arc
     const labelGenerator = d3.arc()
      .innerRadius(innerRadius)
      .outerRadius(outerRadius)
      .startAngle(startAngle)
      .endAngle(endAngle);

    //Draw the arcs themselves
		g.append('path')
			.attr('d', labelGenerator())
			.attr("class", "label-path")
			.attr("id", cat.category.toLowerCase())
      .attr('fill', 'none')
      .attr('stroke', 'black');

    //append text stuff
    g.append("text")
    .attr("class", "cat-text")
    .attr("dy", 17)
    .append("textPath")
    .attr("xlink:href", `#${cat.category.toLowerCase()}`)
    .attr("startOffset", "2%")
    .text(cat.category)
    .style("fill", cat.text_color)
    .style("font-size", fontSize);
}




const DonutShape = ({ width, currentColor }) => {

  const [lookup, setLookup] = useState([]); 
  const [data, setData] = useState([]);

  const {categories} = useAppState();

  const svgRef = useRef();
  const fontSize = `${width * .032}px`;
  const hueFontSize = `${width * .05}px`;


  const wheelCategories = useMemo(() => {
    return categories.filter(cat => cat.hue_start !== null);
  }, [categories]);




   //load up the lookup table
  useEffect(() => {
        fetchRGBLookup().then(data => {
          if (data && Array.isArray(data)) {
            setLookup(data);
          } else {
            console.warn('Lookup fetch returned unexpected data:', data);
          }
        });
    }, []);



//when lookup, wheelCatgories, width get loaded
useEffect(() => {
  if (lookup.length === 0) return;

  const svg = d3.select(svgRef.current)
    .attr('width', width)
    .attr('height', width);

  const centerX = width / 2;
  const centerY = width / 2;

  // Clear any prior arcs (if regenerating)
  svg.selectAll('*').remove();


  // Append label arcs with text
  const g2 = svg.append('g')
    .attr('transform', `translate(${centerX}, ${centerY})`)
    .attr('class', 'label-group');

  wheelCategories.forEach((cat, i) => {
    appendArcToGroup(g2, width, cat, i);
  });

  return () => {
    svg.selectAll('*').remove();
  };

}, [lookup, wheelCategories, width]);




    }, [currentColor, width]);



  return (
    <svg ref={svgRef} >
      {/* SVG element to draw the donut */}
    </svg>
  );
};

export default DonutShape;

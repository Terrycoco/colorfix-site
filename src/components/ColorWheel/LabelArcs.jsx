import { useEffect, useRef, useMemo, useState } from "react";
import * as d3 from "d3";
import { useAppState } from "@context/AppStateContext"; // adjust path to match

const isEven = (n) => n % 2 === 0;

const appendArcToGroup = (g, width, cat, i) => {
  if (!cat) return;

  const startAngle = cat.hue_min * Math.PI / 180;
  const endAngle = cat.hue_max * Math.PI / 180;
  const innerRadius = !isEven(i) ? width * 0.300 : width * 0.388;
  const outerRadius = !isEven(i) ? width * 0.388 : width * 0.476;
  const fontSize = `${width * 0.036}px`;

  const labelGenerator = d3.arc()
    .innerRadius(innerRadius)
    .outerRadius(outerRadius)
    .startAngle(startAngle)
    .endAngle(endAngle);

  g.append("path")
    .attr("d", labelGenerator())
    .attr("class", "label-path")
    .attr("id", cat.name.toLowerCase())
    .attr("fill", "none")
    .attr("stroke", "black");

  g.append("text")
    .attr("class", "cat-text")
    .attr("dy", 17)
    .append("textPath")
    .attr("xlink:href", `#${cat.name.toLowerCase()}`)
    .attr("startOffset", "2%")
    .text(cat.name)
    .style("fill", cat.wheel_text_color)
    .style("font-size", fontSize);
};

export default function LabelArcs({ width }) {
  const { categories } = useAppState(); //smart component
  const [ wheelCategories, setWheelCategories] = useState([]);

  const groupRef = useRef(null);

  useEffect(() => {
    if (categories && categories.length > 0) {

      const hues = categories.filter(cat => cat.type === 'hue');
      console.log('Setting wheelCategories', hues);
      setWheelCategories(hues);
    }
  }, [categories]);



  useEffect(() => {
    const g = d3.select(groupRef.current);
    g.selectAll("*").remove();

    wheelCategories.forEach((cat, i) => {
      appendArcToGroup(g, width, cat, i);
    });
  }, [wheelCategories, width]);

  return (
    <g
      ref={groupRef}
      className="label-group"
      transform={`translate(${width / 2}, ${width / 2})`}
    />
  );
}

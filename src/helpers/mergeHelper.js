// helpers/mergeWithInserts.js

/**
 * Merge static inserts into dynamic results based on insert_position.
 * Inserts with position 0 go at the top, others are placed by index.
 */
export function mergeWithInserts(results = [], inserts = []) {
  // Defensive copy
  let merged = [...results];

  // Sort inserts by insert_position ascending
  const sortedInserts = [...inserts].sort((a, b) => (a.insert_position ?? Infinity) - (b.insert_position ?? Infinity));

  sortedInserts.forEach(insert => {
    const pos = insert.insert_position;

    // If position is 0 or invalid, insert at the top
    if (typeof pos !== 'number' || pos <= 0) {
      merged.unshift(insert);
    } 
    // If position is beyond array length, push at the end
    else if (pos >= merged.length) {
      merged.push(insert);
    } 
    // Otherwise, insert at the given position
    else {
      merged.splice(pos, 0, insert);
    }
  });

  return merged;
}

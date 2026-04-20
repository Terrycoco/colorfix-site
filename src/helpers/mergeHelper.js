// helpers/mergeWithInserts.js

/**
 * Merge query results and static inserts into one ordered stream.
 *
 * Preferred mode:
 * - query result rows use `sort_order`
 * - static inserts use `insert_position`
 * - both are sorted together numerically
 *
 * Fallback mode:
 * - if query rows do not carry `sort_order`, preserve their original order
 * - apply inserts by index-like `insert_position` behavior
 */
export function mergeWithInserts(results = [], inserts = []) {
  const toNumericPosition = (value) => {
    if (value === '' || value == null) return Infinity;
    const parsed = Number.parseFloat(value);
    return Number.isFinite(parsed) ? parsed : Infinity;
  };

  const hasResultSortOrder = results.some((item) => Number.isFinite(toNumericPosition(item?.sort_order)));

  if (hasResultSortOrder) {
    return [...results, ...inserts]
      .map((item, index) => {
        const resultSort = toNumericPosition(item?.sort_order);
        const insertSort = toNumericPosition(item?.insert_position);
        const sortValue = Number.isFinite(resultSort) ? resultSort : insertSort;
        return {
          item,
          index,
          sortValue,
        };
      })
      .sort((a, b) => {
        const aSort = a.sortValue;
        const bSort = b.sortValue;
        if (aSort !== bSort) return aSort - bSort;
        return a.index - b.index;
      })
      .map((entry) => entry.item);
  }

  // Fallback to legacy insert-by-position behavior when query rows have no sort_order.
  let merged = [...results];

  // Sort inserts by insert_position ascending
  const sortedInserts = [...inserts].sort((a, b) => toNumericPosition(a.insert_position) - toNumericPosition(b.insert_position));

  sortedInserts.forEach(insert => {
    const pos = toNumericPosition(insert.insert_position);

    // If position is 0 or invalid, insert at the top
    if (!Number.isFinite(pos) || pos <= 0) {
      merged.unshift(insert);
    } 
    // If position is beyond array length, push at the end
    else if (pos >= merged.length) {
      merged.push(insert);
    } 
    // Otherwise, insert at the given position
    else {
      merged.splice(Math.floor(pos), 0, insert);
    }
  });

  return merged;
}

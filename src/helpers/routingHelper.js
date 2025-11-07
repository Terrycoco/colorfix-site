export function buildResultsUrl(queryId, paramObj = {}) {
  const search = new URLSearchParams(paramObj).toString(); // converts to key=value&key2=value2
  return `/results/${queryId}${search ? `?${search}` : ''}`;
}
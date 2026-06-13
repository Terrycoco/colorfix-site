import "./admin-asset-analytics.css";

export default function AdminAssetAnalyticsPage() {
  return (
    <div className="admin-asset-analytics">
      <header className="assetanalytics-header">
        <h1>Asset Analytics</h1>
        <p>Future home for channel metrics, published-asset performance, and ColorFix pingbacks.</p>
      </header>
      <section className="assetanalytics-panel">
        <table className="assetanalytics-grid">
          <thead>
            <tr>
              <th>Module</th>
              <th>Status</th>
              <th>Notes</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>Channel analytics</td>
              <td>Planned</td>
              <td>YouTube, Pinterest, and future channel reporting.</td>
            </tr>
            <tr>
              <td>Pingbacks</td>
              <td>Planned</td>
              <td>Traffic from tracked publishing URLs back into ColorFix.</td>
            </tr>
          </tbody>
        </table>
      </section>
    </div>
  );
}

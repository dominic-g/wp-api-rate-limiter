import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { Card, CardBody, Spinner, Notice, ExternalLink } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { Line, Bar } from 'react-chartjs-2';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    Title,
    Tooltip,
    Legend,
} from 'chart.js';

ChartJS.register(
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    Title,
    Tooltip,
    Legend
);

const App = () => {
    const [ chartData, setChartData ] = useState( null );
    const [ kpis, setKpis ] = useState( null );
    const [ recentRequests, setRecentRequests ] = useState( null );
    const [ isLoading, setIsLoading ] = useState( true );
    const [ error, setError ] = useState( null );

    // Assume rlmAdminData is localized from the backend
    const apiNamespace = window.rlmAdminData?.namespace || '';
    const apiNonce = window.rlmAdminData?.nonce || '';

    useEffect( () => {
        const fetchData = async () => {
            setIsLoading( true );
            setError( null );

            try {

                const kpisPath = `/${apiNamespace}/dashboard-kpis`;
                const requestsPath = `/${apiNamespace}/recent-requests?per_page=10`;

                // Fetch KPIs
                const kpisResponse = await apiFetch( {
                    path: kpisPath, // Use the relative path
                    headers: { 'X-WP-Nonce': apiNonce },
                } );
                setKpis( kpisResponse );

                // Fetch Recent Requests
                const requestsResponse = await apiFetch( {
                    // path: `${apiRoot}/recent-requests?per_page=10`,
                    path: requestsPath,
                    headers: { 'X-WP-Nonce': apiNonce },
                } );
                setRecentRequests( requestsResponse );

                // Fetch Chart Data
                const chartResponse = await apiFetch( {
                    path: `/${apiNamespace}/chart-data`, // Use the relative path
                    headers: { 'X-WP-Nonce': apiNonce },
                } );
                setChartData( chartResponse );

            } catch ( fetchError ) {
                console.error( 'Error fetching RLM data:', fetchError );
                setError( fetchError.message || __('Failed to load data. Check console for details.', 'wp-api-rate-limiter') );
            } finally {
                setIsLoading( false );
            }
        };

        if ( apiNamespace && apiNonce ) {
            fetchData();
        } else {
            setError( __( 'WordPress REST API root or nonce not available. Check plugin localization data.', 'wp-api-rate-limiter' ) );
            setIsLoading( false );
        }

    }, [ apiNamespace, apiNonce ] );

    if ( error ) {
        return <Notice status="error" isDismissible={ false }>{ error }</Notice>;
    }

    if ( isLoading ) {
        return (
            <Card>
                <CardBody>
                    <Spinner />
                    <p>{ __( 'Loading dashboard data...', 'wp-api-rate-limiter' ) }</p>
                </CardBody>
            </Card>
        );
    }

    return (
        <div className="rlm-dashboard-wrapper">
            <h2>{ __( 'Overview', 'wp-api-rate-limiter' ) }</h2>
            <div className="rlm-kpis-grid">
                <Card>
                    <CardBody>
                        <h3>{ __( 'Total Requests Today', 'wp-api-rate-limiter' ) }</h3>
                        <p className="rlm-kpi-value">{ kpis?.total_requests_today ?? 'N/A' }</p>
                    </CardBody>
                </Card>
                <Card>
                    <CardBody>
                        <h3>{ __( 'Blocked Requests Today', 'wp-api-rate-limiter' ) }</h3>
                        <p className="rlm-kpi-value">{ kpis?.blocked_requests_today ?? 'N/A' }</p>
                    </CardBody>
                </Card>
                <Card>
                    <CardBody>
                        <h3>{ __( 'Blocked Rate', 'wp-api-rate-limiter' ) }</h3>
                        <p className="rlm-kpi-value">{ kpis?.percentage_blocked ? `${kpis.percentage_blocked}%` : 'N/A' }</p>
                    </CardBody>
                </Card>
            </div>

            <div className="rlm-charts-grid">
                <Card>
                    <CardBody>
                        <h2>{ __( 'Requests Over Time (Last 24h)', 'wp-api-rate-limiter' ) }</h2>
                        { chartData && chartData.labels && chartData.total_requests_data ? (
                            <Line
                                data={ {
                                    labels: chartData.labels,
                                    datasets: [
                                        {
                                            label: __( 'Total Requests', 'wp-api-rate-limiter' ),
                                            data: chartData.total_requests_data,
                                            borderColor: 'rgb(75, 192, 192)',
                                            backgroundColor: 'rgba(75, 192, 192, 0.5)',
                                            tension: 0.1,
                                        },
                                        {
                                            label: __( 'Blocked Requests', 'wp-api-rate-limiter' ),
                                            data: chartData.blocked_requests_data,
                                            borderColor: 'rgb(255, 99, 132)',
                                            backgroundColor: 'rgba(255, 99, 132, 0.5)',
                                            tension: 0.1,
                                        },
                                    ],
                                } }
                                options={ {
                                    responsive: true,
                                    plugins: {
                                        legend: {
                                            position: 'top',
                                        },
                                        title: {
                                            display: false,
                                            text: __( 'Requests Over Time', 'wp-api-rate-limiter' ),
                                        },
                                    },
                                    scales: {
                                        y: {
                                            beginAtZero: true,
                                            title: {
                                                display: true,
                                                text: __( 'Number of Requests', 'wp-api-rate-limiter' ),
                                            },
                                        },
                                        x: {
                                            title: {
                                                display: true,
                                                text: __( 'Hour (UTC)', 'wp-api-rate-limiter' ),
                                            },
                                        },
                                    },
                                } }
                            />
                        ) : (
                            <p>{ __( 'No chart data available.', 'wp-api-rate-limiter' ) }</p>
                        ) }
                    </CardBody>
                </Card>
            </div>

            {/* Placeholder for User Distribution by Country (Map) */}
            <Card className="rlm-country-distribution">
                <CardBody>
                    <h2>{ __( 'User Distribution by Country', 'wp-api-rate-limiter' ) }</h2>
                    { chartData && chartData.country_data && chartData.country_data.length > 0 ? (
                        <>
                            <p>{ __( 'This section will feature an interactive map with GeoIP data in a future version.', 'wp-api-rate-limiter' ) }</p>
                            <ul>
                                {chartData.country_data.map((item, index) => (
                                    <li key={index}>
                                        {item.country}: {item.count} {__('requests', 'wp-api-rate-limiter')}
                                    </li>
                                ))}
                            </ul>
                            <p className="rlm-chart-note">{ __( 'Data is currently mock/aggregated by IP. Full GeoIP coming soon.', 'wp-api-rate-limiter' ) }</p>
                        </>
                    ) : (
                        <p>{ __( 'No country data available.', 'wp-api-rate-limiter' ) }</p>
                    ) }
                </CardBody>
            </Card>

            <h2>{ __( 'Recent Requests', 'wp-api-rate-limiter' ) }</h2>
            <Card>
                <CardBody>
                    { recentRequests && recentRequests.length > 0 ? (
                        <table className="wp-list-table widefat fixed striped table-view-list">
                            <thead>
                                <tr>
                                    <th>{__('Time', 'wp-api-rate-limiter')}</th>
                                    <th>{__('IP', 'wp-api-rate-limiter')}</th>
                                    <th>{__('Endpoint', 'wp-api-rate-limiter')}</th>
                                    <th>{__('User', 'wp-api-rate-limiter')}</th>
                                    <th>{__('Status', 'wp-api-rate-limiter')}</th>
                                    <th>{__('Blocked', 'wp-api-rate-limiter')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {recentRequests.map( ( req ) => (
                                    <tr key={ req.id }>
                                        <td>{ req.timestamp_readable }</td>
                                        <td>{ req.ip }</td>
                                        <td>{ req.endpoint }</td>
                                        <td>{ req.user ? `${req.user.login} (${req.user.role})` : __( 'Unauthenticated', 'wp-api-rate-limiter' ) }</td>
                                        <td>{ req.status_code }</td>
                                        <td>{ req.is_blocked ? __('Yes', 'wp-api-rate-limiter') : __('No', 'wp-api-rate-limiter') }</td>
                                    </tr>
                                ) )}
                            </tbody>
                        </table>
                    ) : (
                        <p>{ __( 'No recent requests to display.', 'wp-api-rate-limiter' ) }</p>
                    ) }
                </CardBody>
            </Card>

            { kpis?.top_ips_today && kpis.top_ips_today.length > 0 && (
                <>
                    <h2>{ __( 'Top 5 Offending IPs Today', 'wp-api-rate-limiter' ) }</h2>
                    <Card>
                        <CardBody>
                            <ul>
                                {kpis.top_ips_today.map((item, index) => (
                                    <li key={index}>{item.ip}: {item.count} {__('requests', 'wp-api-rate-limiter')}</li>
                                ))}
                            </ul>
                        </CardBody>
                    </Card>
                </>
            )}
        </div>
    );
};

export default App;
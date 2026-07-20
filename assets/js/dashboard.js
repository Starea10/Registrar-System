/**
 * Dashboard JavaScript
 * Handles all chart rendering and interactivity for the admin dashboard
 */

// Global chart instances
let monthlyChart = null;
let dailyChart = null;
let releasedChart = null;
let dailyReleasedChart = null;

// Chart data variables (will be populated by PHP)
let monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
let monthlyData = [];
let dailyData = [];
let daysInMonth = 0;
let releasedDataByYear = {};
let dailyReleasedDataByYearMonth = {};
let availableYears = [];
let selectedYear = new Date().getFullYear();
let selectedMonth = new Date().getMonth() + 1;

/**
 * Initialize dashboard data
 * Call this function from PHP with the required data
 */
function initializeDashboardData(data) {
    monthlyData = data.monthlyData;
    dailyData = data.dailyData;
    daysInMonth = data.daysInMonth;
    releasedDataByYear = data.releasedDataByYear;
    dailyReleasedDataByYearMonth = data.dailyReleasedDataByYearMonth;
    availableYears = data.availableYears;
    selectedYear = data.selectedYear;
    selectedMonth = data.selectedMonth;
    
    // Initialize charts after data is loaded
    initializeMonthlyChart();
    initializeDailyChart();
}

/**
 * Initialize Monthly Chart
 */
function initializeMonthlyChart() {
    const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');

    // Create gradient for monthly chart
    const monthlyGradient = monthlyCtx.createLinearGradient(0, 0, 0, 400);
    monthlyGradient.addColorStop(0, 'rgba(76, 175, 80, 0.1)');
    monthlyGradient.addColorStop(1, 'rgba(76, 175, 80, 0)');

    monthlyChart = new Chart(monthlyCtx, {
        type: 'line',
        data: {
            labels: monthNames,
            datasets: [{
                label: 'Requests',
                data: monthlyData,
                borderColor: '#4caf50',
                backgroundColor: monthlyGradient,
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointRadius: 6,
                pointHoverRadius: 8,
                pointBackgroundColor: '#4caf50',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointHoverBackgroundColor: '#388e3c',
                pointHoverBorderColor: '#ffffff',
                pointHoverBorderWidth: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                intersect: false,
                mode: 'index'
            },
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(46, 46, 46, 0.95)',
                    titleColor: '#ffffff',
                    bodyColor: '#ffffff',
                    borderColor: '#4caf50',
                    borderWidth: 1,
                    cornerRadius: 8,
                    displayColors: false,
                    titleFont: {
                        size: 14,
                        weight: '600'
                    },
                    bodyFont: {
                        size: 13
                    },
                    callbacks: {
                        title: function(context) {
                            return context[0].label + ' ' + selectedYear;
                        },
                        label: function(context) {
                            return context.parsed.y + ' requests';
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false
                    },
                    border: {
                        display: false
                    },
                    ticks: {
                        color: '#666',
                        font: {
                            size: 12,
                            weight: '500'
                        }
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)',
                        drawBorder: false
                    },
                    border: {
                        display: false
                    },
                    ticks: {
                        color: '#666',
                        font: {
                            size: 12
                        },
                        padding: 10,
                        callback: function(value) {
                            return Number.isInteger(value) ? value : '';
                        }
                    }
                }
            }
        }
    });
}

/**
 * Initialize Daily Lollipop Chart
 */
function initializeDailyChart() {
    const dailyCtx = document.getElementById('dailyChart').getContext('2d');
    const dailyLabels = Array.from({length: daysInMonth}, (_, i) => (i + 1).toString());

    dailyChart = new Chart(dailyCtx, {
        type: 'scatter',
        data: {
            labels: dailyLabels,
            datasets: [
                // Stems (lines)
                {
                    label: 'Stems',
                    data: dailyData.map((value, index) => ({
                        x: index + 1,
                        y: 0
                    })),
                    backgroundColor: 'transparent',
                    borderColor: '#17a2b8',
                    borderWidth: 2,
                    pointRadius: 0,
                    showLine: false,
                    tension: 0
                },
                // Lollipops (points)
                {
                    label: 'Daily Requests',
                    data: dailyData.map((value, index) => ({
                        x: index + 1,
                        y: value
                    })),
                    backgroundColor: '#17a2b8',
                    borderColor: '#ffffff',
                    borderWidth: 2,
                    pointRadius: function(context) {
                        return context.parsed.y === 0 ? 0 : 8;
                    },
                    pointHoverRadius: function(context) {
                        return context.parsed.y === 0 ? 0 : 12;
                    },
                    showLine: false
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                intersect: false,
                mode: 'point'
            },
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(46, 46, 46, 0.95)',
                    titleColor: '#ffffff',
                    bodyColor: '#ffffff',
                    borderColor: '#17a2b8',
                    borderWidth: 1,
                    cornerRadius: 8,
                    displayColors: false,
                    titleFont: {
                        size: 14,
                        weight: '600'
                    },
                    bodyFont: {
                        size: 13
                    },
                    filter: function(tooltipItem) {
                        return tooltipItem.datasetIndex === 1;
                    },
                    callbacks: {
                        title: function(context) {
                            const day = context[0].parsed.x;
                            const date = new Date(selectedYear, selectedMonth - 1, day);
                            return date.toLocaleDateString('en-US', { 
                                weekday: 'long',
                                month: 'short',
                                day: 'numeric'
                            });
                        },
                        label: function(context) {
                            return context.parsed.y + ' requests';
                        }
                    }
                }
            },
            scales: {
                x: {
                    type: 'linear',
                    position: 'bottom',
                    min: 0.5,
                    max: daysInMonth + 0.5,
                    grid: {
                        display: false
                    },
                    border: {
                        display: false
                    },
                    ticks: {
                        color: '#666',
                        font: {
                            size: 11,
                            weight: '500'
                        },
                        stepSize: 1,
                        callback: function(value) {
                            return Number.isInteger(value) && value > 0 && value <= daysInMonth ? value : '';
                        }
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)',
                        drawBorder: false
                    },
                    border: {
                        display: false
                    },
                    ticks: {
                        color: '#666',
                        font: {
                            size: 12
                        },
                        padding: 10,
                        callback: function(value) {
                            return Number.isInteger(value) ? value : '';
                        }
                    }
                }
            }
        },
        plugins: [{
            // Plugin to draw stems
            afterDatasetsDraw: function(chart) {
                const ctx = chart.ctx;
                const meta = chart.getDatasetMeta(1); // Get lollipop dataset
                
                ctx.save();
                ctx.strokeStyle = '#17a2b8';
                ctx.lineWidth = 2;
                
                meta.data.forEach((point, index) => {
                    if (dailyData[index] > 0) {
                        const x = point.x;
                        const yTop = point.y;
                        const yBottom = chart.scales.y.getPixelForValue(0);
                        
                        ctx.beginPath();
                        ctx.moveTo(x, yBottom);
                        ctx.lineTo(x, yTop);
                        ctx.stroke();
                    }
                });
                
                ctx.restore();
            }
        }]
    });
}

/**
 * Show released modal
 */
function showReleasedModal() {
    const modal = new bootstrap.Modal(document.getElementById('releasedModal'));
    modal.show();
    
    // Initialize charts when modal is shown
    setTimeout(() => {
        initializeReleasedChart();
        initializeDailyReleasedChart();
    }, 300);
}

function redirectToRequestPage(pageStatus){
    window.location.href = "requests.php?status=" + pageStatus;
}

/**
 * Switch tabs in modal
 */
function switchTab(tabName) {
    // Update tab buttons
    document.querySelectorAll('.modal-tab').forEach(tab => {
        tab.classList.remove('active');
    });
    event.target.classList.add('active');
    
    // Update tab content
    document.querySelectorAll('.modal-tab-content').forEach(content => {
        content.classList.remove('active');
    });
    document.getElementById(tabName + 'Tab').classList.add('active');
    
    // Initialize charts based on active tab
    setTimeout(() => {
        if (tabName === 'monthly') {
            if (releasedChart) {
                releasedChart.resize();
            } else {
                initializeReleasedChart();
            }
        } else if (tabName === 'daily') {
            if (dailyReleasedChart) {
                dailyReleasedChart.resize();
            } else {
                initializeDailyReleasedChart();
            }
        }
    }, 100);
}

/**
 * Initialize released chart
 */
function initializeReleasedChart() {
    const ctx = document.getElementById('releasedChart').getContext('2d');
    const modalSelectedYear = document.getElementById('modalYearSelect').value;
    const yearData = releasedDataByYear[modalSelectedYear] || Array(12).fill(0);
    const chartData = Object.values(yearData);
    
    // Create gradient for the bar chart
    const gradient = ctx.createLinearGradient(0, 0, 0, 400);
    gradient.addColorStop(0, 'rgba(76, 175, 80, 0.8)');
    gradient.addColorStop(1, 'rgba(76, 175, 80, 0.2)');
    
    // Destroy existing chart if it exists
    if (releasedChart) {
        releasedChart.destroy();
    }
    
    releasedChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: monthNames,
            datasets: [{
                label: 'Released Requests',
                data: chartData,
                backgroundColor: gradient,
                borderColor: '#4caf50',
                borderWidth: 2,
                borderRadius: 8,
                borderSkipped: false,
                hoverBackgroundColor: 'rgba(76, 175, 80, 0.9)',
                hoverBorderColor: '#388e3c',
                hoverBorderWidth: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                intersect: false,
                mode: 'index'
            },
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(46, 46, 46, 0.95)',
                    titleColor: '#ffffff',
                    bodyColor: '#ffffff',
                    borderColor: '#4caf50',
                    borderWidth: 1,
                    cornerRadius: 8,
                    displayColors: false,
                    titleFont: {
                        size: 14,
                        weight: '600'
                    },
                    bodyFont: {
                        size: 13
                    },
                    callbacks: {
                        title: function(context) {
                            return context[0].label + ' ' + modalSelectedYear;
                        },
                        label: function(context) {
                            return context.parsed.y + ' released requests';
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false
                    },
                    border: {
                        display: false
                    },
                    ticks: {
                        color: '#666',
                        font: {
                            size: 12,
                            weight: '500'
                        }
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)',
                        drawBorder: false
                    },
                    border: {
                        display: false
                    },
                    ticks: {
                        color: '#666',
                        font: {
                            size: 12
                        },
                        padding: 10,
                        callback: function(value) {
                            return Number.isInteger(value) ? value : '';
                        }
                    }
                }
            },
            animation: {
                duration: 1000,
                easing: 'easeOutQuart'
            }
        }
    });
    
    // Update total released count if element exists
    const totalElement = document.getElementById('totalReleased');
    if (totalElement) {
        const total = chartData.reduce((sum, value) => sum + value, 0);
        totalElement.textContent = total.toLocaleString();
    }
}

/**
 * Initialize daily released chart
 */
function initializeDailyReleasedChart() {
    const ctx = document.getElementById('dailyReleasedChart').getContext('2d');
    const dailySelectedYear = parseInt(document.getElementById('dailyYearSelect').value);
    const dailySelectedMonth = parseInt(document.getElementById('dailyMonthSelect').value);
    
    // Get data for selected year and month
    let chartDailyData = [];
    const daysInSelectedMonth = new Date(dailySelectedYear, dailySelectedMonth, 0).getDate();
    
    if (dailyReleasedDataByYearMonth[dailySelectedYear] && dailyReleasedDataByYearMonth[dailySelectedYear][dailySelectedMonth]) {
        chartDailyData = Object.values(dailyReleasedDataByYearMonth[dailySelectedYear][dailySelectedMonth]);
    } else {
        chartDailyData = Array(daysInSelectedMonth).fill(0);
    }
    
    // Create gradient for the bar chart
    const gradient = ctx.createLinearGradient(0, 0, 0, 350);
    gradient.addColorStop(0, 'rgba(253, 126, 20, 0.8)');
    gradient.addColorStop(1, 'rgba(253, 126, 20, 0.2)');
    
    // Destroy existing chart if it exists
    if (dailyReleasedChart) {
        dailyReleasedChart.destroy();
    }
    
    // Create day labels
    const dayLabels = Array.from({length: daysInSelectedMonth}, (_, i) => (i + 1).toString());
    
    dailyReleasedChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: dayLabels,
            datasets: [{
                label: 'Daily Released Requests',
                data: chartDailyData,
                backgroundColor: gradient,
                borderColor: '#fd7e14',
                borderWidth: 2,
                borderRadius: 6,
                borderSkipped: false,
                hoverBackgroundColor: 'rgba(253, 126, 20, 0.9)',
                hoverBorderColor: '#e8681c',
                hoverBorderWidth: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                intersect: false,
                mode: 'index'
            },
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(46, 46, 46, 0.95)',
                    titleColor: '#ffffff',
                    bodyColor: '#ffffff',
                    borderColor: '#fd7e14',
                    borderWidth: 1,
                    cornerRadius: 8,
                    displayColors: false,
                    titleFont: {
                        size: 14,
                        weight: '600'
                    },
                    bodyFont: {
                        size: 13
                    },
                    callbacks: {
                        title: function(context) {
                            const day = parseInt(context[0].label);
                            const date = new Date(dailySelectedYear, dailySelectedMonth - 1, day);
                            return date.toLocaleDateString('en-US', { 
                                weekday: 'long',
                                month: 'short',
                                day: 'numeric',
                                year: 'numeric'
                            });
                        },
                        label: function(context) {
                            return context.parsed.y + ' released requests';
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false
                    },
                    border: {
                        display: false
                    },
                    ticks: {
                        color: '#666',
                        font: {
                            size: 11,
                            weight: '500'
                        },
                        maxTicksLimit: 15 // Limit number of ticks for better readability
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)',
                        drawBorder: false
                    },
                    border: {
                        display: false
                    },
                    ticks: {
                        color: '#666',
                        font: {
                            size: 12
                        },
                        padding: 10,
                        callback: function(value) {
                            return Number.isInteger(value) ? value : '';
                        }
                    }
                }
            },
            animation: {
                duration: 1000,
                easing: 'easeOutQuart'
            }
        }
    });
    
    // Update monthly released count if element exists
    const monthlyElement = document.getElementById('monthlyReleased');
    if (monthlyElement) {
        const monthlyTotal = chartDailyData.reduce((sum, value) => sum + value, 0);
        monthlyElement.textContent = monthlyTotal.toLocaleString();
    }
}

/**
 * Update released chart when year changes
 */
function updateReleasedChart() {
    if (releasedChart) {
        const modalSelectedYear = document.getElementById('modalYearSelect').value;
        const yearData = releasedDataByYear[modalSelectedYear] || Array(12).fill(0);
        const chartData = Object.values(yearData);
        
        releasedChart.data.datasets[0].data = chartData;
        releasedChart.update('active');
        
        // Update total released count if element exists
        const totalElement = document.getElementById('totalReleased');
        if (totalElement) {
            const total = chartData.reduce((sum, value) => sum + value, 0);
            totalElement.textContent = total.toLocaleString();
        }
    }
}

/**
 * Update daily released chart when year or month changes
 */
function updateDailyReleasedChart() {
    const dailySelectedYear = parseInt(document.getElementById('dailyYearSelect').value);
    const dailySelectedMonth = parseInt(document.getElementById('dailyMonthSelect').value);
    
    if (dailyReleasedChart) {
        // Get data for selected year and month
        let chartDailyData = [];
        const daysInSelectedMonth = new Date(dailySelectedYear, dailySelectedMonth, 0).getDate();
        
        if (dailyReleasedDataByYearMonth[dailySelectedYear] && dailyReleasedDataByYearMonth[dailySelectedYear][dailySelectedMonth]) {
            chartDailyData = Object.values(dailyReleasedDataByYearMonth[dailySelectedYear][dailySelectedMonth]);
        } else {
            chartDailyData = Array(daysInSelectedMonth).fill(0);
        }
        
        // Create new day labels
        const dayLabels = Array.from({length: daysInSelectedMonth}, (_, i) => (i + 1).toString());
        
        dailyReleasedChart.data.labels = dayLabels;
        dailyReleasedChart.data.datasets[0].data = chartDailyData;
        dailyReleasedChart.update('active');
        
        // Update monthly released count if element exists
        const monthlyElement = document.getElementById('monthlyReleased');
        if (monthlyElement) {
            const monthlyTotal = chartDailyData.reduce((sum, value) => sum + value, 0);
            monthlyElement.textContent = monthlyTotal.toLocaleString();
        }
    } else {
        initializeDailyReleasedChart();
    }
}

/**
 * Handle year change for main dashboard
 */
function changeYear() {
    const newSelectedYear = document.getElementById('yearSelect').value;
    const currentMonth = selectedMonth;
    window.location.href = `dashboard.php?year=${newSelectedYear}&month=${currentMonth}`;
}

/**
 * Handle month change for main dashboard
 */
function changeMonth() {
    const newSelectedMonth = document.getElementById('monthSelect').value;
    const currentYear = selectedYear;
    window.location.href = `dashboard.php?year=${currentYear}&month=${newSelectedMonth}`;
}

/**
 * Sidebar functionality
 */
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    
    if (sidebar && overlay) {
        sidebar.classList.toggle('show');
        overlay.classList.toggle('show');
    }
}

function closeSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    
    if (sidebar && overlay) {
        sidebar.classList.remove('show');
        overlay.classList.remove('show');
    }
}
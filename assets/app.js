const map = L.map('map', { zoomControl: true }).setView([30.168, 75.845], 13); // Default view centered on Sangrur school
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  attribution: '&copy; OpenStreetMap contributors',
}).addTo(map);

let busMarker = null;
let routePolylineGroup = L.layerGroup().addTo(map);

let pollTimer = null;
let tickTimer = null;
let lastFetchAt = null; // Date.now() of last successful update
let currentTrackingTarget = null; // Currently tracked student target { studentId, studentName, studentClass }

let checkpointsLayerGroup = L.layerGroup().addTo(map);
let checkpointsData = [];
let showCheckpoints = true;

const POLL_MS = 8000; // Poll bus location every 8s

// --- Element references ---
const studentNameInput   = document.getElementById('studentName');
const studentClassSelect = document.getElementById('studentClass');
const searchResultsEl    = document.getElementById('searchResults');
const trackBtn           = document.getElementById('trackBtn');
const newSearchBtn       = document.getElementById('newSearchBtn');

const statusBanner = document.getElementById('statusBanner');
const statusIcon   = document.getElementById('statusIcon');
const statusText   = document.getElementById('statusText');

const emptyState      = document.getElementById('emptyState');
const infoSheet       = document.getElementById('infoSheet');
const studentNameEl   = document.getElementById('studentNameEl') || document.getElementById('studentName');
const studentClassEl  = document.getElementById('studentClassEl');
const busStatusBadge  = document.getElementById('busStatusBadge');
const busNoEl         = document.getElementById('busNo');
const routeNameEl     = document.getElementById('routeNameEl');
const yourStopEl      = document.getElementById('yourStopEl');
const nearestStopEl   = document.getElementById('nearestStopEl');
const lastUpdatedEl   = document.getElementById('lastUpdated');
const toggleStopsBtn  = document.getElementById('toggleStopsBtn');
const stopsCountLabel = document.getElementById('stopsCountLabel');

let yourStopMarker = null; // Pulsing marker on student's assigned stop

// Custom Map Icons
const busIcon = L.divIcon({
  className: 'bus-icon',
  html: '<div class="bus-icon-wrap">🚌</div>',
  iconSize: [44, 44],
  iconAnchor: [22, 22],
});

const stopIcon = L.divIcon({
  className: 'stop-icon',
  html: '<div class="stop-icon-wrap">🚏</div>',
  iconSize: [26, 26],
  iconAnchor: [13, 13],
});

const schoolIcon = L.divIcon({
  className: 'school-icon',
  html: '<div class="school-icon-wrap" style="background:#16273c;color:#fff;border-radius:50%;width:30px;height:30px;display:flex;align-items:center;justify-content:center;font-size:18px;border:2px solid #ffc93c;box-shadow:0 3px 8px rgba(0,0,0,0.3);">🏫</div>',
  iconSize: [30, 30],
  iconAnchor: [15, 15],
});

// --- Status banner helpers ---
function showStatus(message, kind = 'info') {
  statusBanner.classList.remove('hidden', 'status-error', 'status-success', 'status-warn');
  if (kind === 'error') {
    statusBanner.classList.add('status-error');
    statusIcon.textContent = '⚠️';
  } else if (kind === 'success') {
    statusBanner.classList.add('status-success');
    statusIcon.textContent = '✅';
  } else if (kind === 'warn') {
    statusBanner.classList.add('status-warn');
    statusIcon.textContent = '🏫';
  } else {
    statusIcon.textContent = 'ℹ️';
  }
  statusText.innerHTML = message;
}

function hideStatus() {
  statusBanner.classList.add('hidden');
}

function distanceMeters(lat1, lng1, lat2, lng2) {
  const R = 6371000;
  const dLat = ((lat2 - lat1) * Math.PI) / 180;
  const dLng = ((lng2 - lng1) * Math.PI) / 180;
  const a =
    Math.sin(dLat / 2) ** 2 +
    Math.cos((lat1 * Math.PI) / 180) *
      Math.cos((lat2 * Math.PI) / 180) *
      Math.sin(dLng / 2) ** 2;
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

function updateBusMarker(lat, lng) {
  const latLng = [lat, lng];
  if (!busMarker) {
    busMarker = L.marker(latLng, { icon: busIcon, zIndexOffset: 1000 }).addTo(map);
  } else {
    busMarker.setLatLng(latLng);
  }
}

/**
 * Draw complete bus route path on map connecting all stops in order + School
 */
function drawBusRoutePath(stops, school, busLat, busLng) {
  routePolylineGroup.clearLayers();

  const waypoints = [];
  const allBounds = [];

  if (busLat != null && busLng != null) {
    allBounds.push([busLat, busLng]);
  }

  // Add route stop waypoints
  if (Array.isArray(stops)) {
    stops.forEach((st, idx) => {
      if (st.lat != null && st.lng != null) {
        const pt = [st.lat, st.lng];
        waypoints.push(pt);
        allBounds.push(pt);

        // Add stop sequence badge marker (1, 2, 3...)
        const seqIcon = L.divIcon({
          className: 'seq-icon',
          html: `<div class="seq-icon-wrap" style="background:#ffc93c;color:#16273c;font-size:12px;font-weight:800;border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;border:2px solid #16273c;box-shadow:0 2px 5px rgba(0,0,0,0.25);">${idx + 1}</div>`,
          iconSize: [22, 22],
          iconAnchor: [11, 11]
        });
        const seqMarker = L.marker(pt, { icon: seqIcon, zIndexOffset: 400 })
          .bindPopup(`<div class="checkpoint-popup"><div class="cp-title">🚏 <strong>Stop #${idx + 1}: ${escapeHtml(st.name)}</strong></div>${st.landmark ? `<div class="cp-landmark">📍 ${escapeHtml(st.landmark)}</div>` : ''}</div>`);
        routePolylineGroup.addLayer(seqMarker);
      }
    });
  }

  // Add School to end of route path
  if (school && school.lat != null && school.lng != null) {
    const schoolPt = [school.lat, school.lng];
    waypoints.push(schoolPt);
    allBounds.push(schoolPt);

    const schoolMarker = L.marker(schoolPt, { icon: schoolIcon, zIndexOffset: 450 })
      .bindPopup(`<div class="checkpoint-popup"><div class="cp-title">🏫 <strong>${escapeHtml(school.name)}</strong></div></div>`);
    routePolylineGroup.addLayer(schoolMarker);
  }

  // Draw full polyline connecting stops and school
  if (waypoints.length > 1) {
    const shadowLine = L.polyline(waypoints, {
      color: '#16273c',
      weight: 8,
      opacity: 0.6,
      lineCap: 'round',
      lineJoin: 'round'
    });
    const mainLine = L.polyline(waypoints, {
      color: '#ffc93c',
      weight: 5,
      opacity: 0.95,
      dashArray: '10, 8',
      lineCap: 'round',
      lineJoin: 'round'
    });
    routePolylineGroup.addLayer(shadowLine);
    routePolylineGroup.addLayer(mainLine);
  }

  if (allBounds.length > 0) {
    map.fitBounds(allBounds, { padding: [60, 60], maxZoom: 15 });
  }
}

/**
 * Detailed motion status in clear English for parents
 */
function updateBusStatus(busStatus, speed, nearestCheckpoint) {
  busStatusBadge.className = 'bus-status-badge';
  hideStatus();

  const speedKm = speed != null ? Math.round(speed) : 0;

  if (busStatus === 'at_school') {
    busStatusBadge.textContent = '🏫 At School Campus';
    busStatusBadge.classList.add('badge-at-school');
    showStatus('🏫 Bus is currently at the school campus and has not departed yet.', 'warn');
  } else if (busStatus === 'stopped') {
    busStatusBadge.textContent = '⏸️ Bus Stationary';
    busStatusBadge.classList.add('badge-stopped');
    if (nearestCheckpoint) {
      showStatus(`⏸️ Bus is currently stopped near ${nearestCheckpoint.name} (${nearestCheckpoint.distanceMeters}m away).`, 'info');
    } else {
      showStatus('⏸️ Bus is currently stopped at a designated stop.', 'info');
    }
  } else {
    // Moving
    busStatusBadge.textContent = `🚚 Bus In Transit (${speedKm} km/h)`;
    busStatusBadge.classList.add('badge-moving');
    if (nearestCheckpoint) {
      showStatus(`🚚 Bus is currently moving (${speedKm} km/h). Nearest stop: ${nearestCheckpoint.name}.`, 'success');
    } else {
      showStatus(`🚚 Bus is currently moving along its route (${speedKm} km/h).`, 'success');
    }
  }
}

// --- "Updated Xs ago" label, ticks every second ---
function formatAgo(ms) {
  const seconds = Math.max(0, Math.round(ms / 1000));
  if (seconds < 5) return 'just now';
  if (seconds < 60) return `${seconds} seconds ago`;
  const minutes = Math.round(seconds / 60);
  if (minutes === 1) return '1 minute ago';
  return `${minutes} minutes ago`;
}

function startTicking() {
  stopTicking();
  tickTimer = setInterval(() => {
    if (lastFetchAt) {
      lastUpdatedEl.textContent = formatAgo(Date.now() - lastFetchAt);
    }
  }, 1000);
}

function stopTicking() {
  if (tickTimer) clearInterval(tickTimer);
  tickTimer = null;
}

// --- Main fetch/render cycle ---
async function fetchAndRender(target) {
  try {
    currentTrackingTarget = target;
    const reqBody = typeof target === 'string'
      ? { student_id: target }
      : { student_id: target.studentId || '', student_name: target.studentName || '', class: target.studentClass || '' };

    const res = await fetch('/api/track.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(reqBody),
    });
    const data = await res.json();

    if (!res.ok) {
      showStatus(friendlyError(data.error), 'error');
      stopPolling();
      return;
    }

    // Handle multiple student matches
    if (data.multipleMatches && Array.isArray(data.students)) {
      renderMultipleMatches(data.students);
      stopPolling();
      return;
    }

    emptyState.classList.add('hidden');
    infoSheet.classList.remove('hidden');
    document.querySelector('.app').classList.add('tracking-active');
    refreshMapSize();

    if (studentNameEl) studentNameEl.textContent = data.student.name;
    if (studentClassEl) studentClassEl.textContent = data.student.class ? `(${data.student.class})` : '';
    if (busNoEl) busNoEl.textContent = data.bus.vehicleNo;
    if (routeNameEl) routeNameEl.textContent = data.bus.routeName || '—';

    // Your assigned stop
    if (yourStopEl) {
      const stop = data.student.stop;
      if (stop) {
        yourStopEl.textContent = stop.name;
        if (yourStopMarker) { map.removeLayer(yourStopMarker); yourStopMarker = null; }
        if (stop.lat != null && stop.lng != null) {
          const yourStopIcon = L.divIcon({
            className: 'your-stop-icon',
            html: '<div class="your-stop-icon-wrap">📍</div>',
            iconSize: [32, 32],
            iconAnchor: [16, 16],
          });
          yourStopMarker = L.marker([stop.lat, stop.lng], { icon: yourStopIcon, zIndexOffset: 500 })
            .bindPopup(`<div class="checkpoint-popup"><div class="cp-title">📍 <strong>Your Child's Pickup Stop</strong></div><div class="cp-stop-name">${escapeHtml(stop.name)}</div>${stop.landmark ? `<div class="cp-landmark">📍 ${escapeHtml(stop.landmark)}</div>` : ''}<div class="cp-fee">Fee: <strong>₹${stop.fee}</strong></div></div>`)
            .addTo(map);
        }
      } else {
        yourStopEl.textContent = '—';
      }
    }

    // Nearest stop
    if (nearestStopEl) {
      if (data.nearestCheckpoint) {
        const cp = data.nearestCheckpoint;
        const distStr = cp.distanceMeters < 1000 ? `${cp.distanceMeters} m` : `${cp.distanceKm} km`;
        nearestStopEl.textContent = `${cp.name} (${distStr} away)`;
      } else {
        nearestStopEl.textContent = '—';
      }
    }

    lastFetchAt = Date.now();
    lastUpdatedEl.textContent = 'just now';
    startTicking();

    const { lat, lng, speed } = data.location || {};
    if (lat == null || lng == null) {
      showStatus("Bus location sync in progress... Please check back shortly.", 'error');
      busStatusBadge.textContent = '—';
      busStatusBadge.className = 'bus-status-badge';
      return;
    }

    // Render Bus Marker & Motion Status
    updateBusMarker(lat, lng);
    updateBusStatus(data.busStatus || 'moving', speed, data.nearestCheckpoint);

    // Draw Complete Bus Path & Waypoints on Map
    drawBusRoutePath(data.bus.stops, data.school, lat, lng);

  } catch (err) {
    console.error(err);
    showStatus("Tracker server network unreachable. Please check your internet connection.", 'error');
  }
}

/**
 * Show choice list if multiple students match search query
 */
function renderMultipleMatches(studentsList) {
  let html = `<div style="margin-bottom:8px;font-weight:700;">We found ${studentsList.length} students matching your search. Tap your child:</div>`;
  html += `<div style="display:flex;flex-direction:column;gap:6px;max-height:220px;overflow-y:auto;">`;
  studentsList.forEach(st => {
    html += `<div class="match-item" onclick="selectStudentMatch('${st.id}')" style="background:#ffffff;padding:8px 12px;border-radius:8px;border:1.5px solid var(--navy-700);cursor:pointer;display:flex;justify-content:space-between;align-items:center;">
      <div>
        <strong style="color:var(--navy-900);">${escapeHtml(st.name)}</strong>
        <span style="font-size:12px;background:var(--amber-bg);color:#7a5206;padding:1px 6px;border-radius:10px;margin-left:6px;">Class ${escapeHtml(st.class)}</span>
        <div style="font-size:11.5px;color:var(--ink-soft);margin-top:2px;">📍 ${escapeHtml(st.address || st.stop)}</div>
      </div>
      <button type="button" style="background:var(--navy-900);color:#fff;border:none;padding:4px 10px;border-radius:6px;font-size:12px;font-weight:700;">Select ➔</button>
    </div>`;
  });
  html += `</div>`;
  showStatus(html, 'info');
}

window.selectStudentMatch = function(studentId) {
  hideStatus();
  startPolling({ studentId });
};

// --- Checkpoints / Bus Stops loader & rendering ---
function escapeHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

async function loadCheckpoints() {
  try {
    const res = await fetch('/api/checkpoints.php');
    if (!res.ok) return;
    const data = await res.json();
    checkpointsData = data.checkpoints || [];
    renderCheckpointMarkers();
    if (stopsCountLabel) {
      stopsCountLabel.textContent = `Stops (${checkpointsData.length})`;
    }
  } catch (err) {
    console.error('Failed to load checkpoints:', err);
  }
}

function renderCheckpointMarkers() {
  checkpointsLayerGroup.clearLayers();
  
  checkpointsData.forEach(cp => {
    if (cp.lat == null || cp.lng == null) return;
    const marker = L.marker([cp.lat, cp.lng], { icon: stopIcon });
    
    let popupHtml = `<div class="checkpoint-popup">
      <div class="cp-title">🚏 <strong>${escapeHtml(cp.name)}</strong></div>`;
    if (cp.landmark) {
      popupHtml += `<div class="cp-landmark">📍 ${escapeHtml(cp.landmark)}</div>`;
    }
    popupHtml += `<div class="cp-fee">Fee: <strong>₹${cp.fee}</strong></div>
      <div class="cp-status">Status: <span class="badge-active">${escapeHtml(cp.status || 'Active')}</span></div>
    </div>`;
    
    marker.bindPopup(popupHtml);
    checkpointsLayerGroup.addLayer(marker);
  });
}

function friendlyError(rawError) {
  switch (rawError) {
    case 'Please enter a student name or ID to track.':
      return 'Please enter your child\'s name to track.';
    case 'No student found matching this name and class.':
      return "No student found with this name and class. Please check the spelling or select a different class.";
    case 'No bus mapped to this student':
      return "No bus is mapped to this student. Please contact the school office.";
    default:
      return rawError || "Having trouble connecting to the bus tracker. Please try again shortly.";
  }
}

// Real-time Autocomplete suggestions
let debounceTimer = null;
studentNameInput.addEventListener('input', () => {
  clearTimeout(debounceTimer);
  const q = studentNameInput.value.trim();
  const cls = studentClassSelect.value;

  if (q.length < 2) {
    searchResultsEl.classList.add('hidden');
    searchResultsEl.innerHTML = '';
    return;
  }

  debounceTimer = setTimeout(async () => {
    try {
      const res = await fetch(`/api/search.php?query=${encodeURIComponent(q)}&class=${encodeURIComponent(cls)}`);
      if (!res.ok) return;
      const data = await res.json();
      renderSuggestions(data.students || []);
    } catch (e) {
      console.error(e);
    }
  }, 200);
});

studentClassSelect.addEventListener('change', () => {
  if (studentNameInput.value.trim().length >= 2) {
    studentNameInput.dispatchEvent(new Event('input'));
  }
});

function renderSuggestions(students) {
  if (students.length === 0) {
    searchResultsEl.classList.add('hidden');
    searchResultsEl.innerHTML = '';
    return;
  }

  let html = '';
  students.forEach(s => {
    html += `<div class="suggestion-item" data-id="${s.id}" data-name="${escapeHtml(s.name)}">
      <div class="sugg-name"><strong>${escapeHtml(s.name)}</strong> <span class="sugg-class">Class ${escapeHtml(s.class)}</span></div>
      <div class="sugg-addr">📍 ${escapeHtml(s.address || s.stop)}</div>
    </div>`;
  });

  searchResultsEl.innerHTML = html;
  searchResultsEl.classList.remove('hidden');

  document.querySelectorAll('.suggestion-item').forEach(item => {
    item.addEventListener('click', () => {
      const studentId = item.getAttribute('data-id');
      const studentName = item.getAttribute('data-name');
      studentNameInput.value = studentName;
      searchResultsEl.classList.add('hidden');
      startPolling({ studentId });
    });
  });
}

// Close autocomplete dropdown when clicking outside
document.addEventListener('click', (e) => {
  if (!searchResultsEl.contains(e.target) && e.target !== studentNameInput) {
    searchResultsEl.classList.add('hidden');
  }
});

function startPolling(target) {
  stopPolling();
  showStatus('Fetching live bus location…');
  fetchAndRender(target);
  pollTimer = setInterval(() => fetchAndRender(target), POLL_MS);
}

function stopPolling() {
  if (pollTimer) clearInterval(pollTimer);
  pollTimer = null;
}

function resetToSearch() {
  stopPolling();
  stopTicking();
  hideStatus();
  if (yourStopMarker) { map.removeLayer(yourStopMarker); yourStopMarker = null; }
  routePolylineGroup.clearLayers();
  infoSheet.classList.add('hidden');
  emptyState.classList.remove('hidden');
  document.querySelector('.app').classList.remove('tracking-active');
  refreshMapSize();
  studentNameInput.value = '';
  studentNameInput.focus();
}

// --- Wire up controls ---
trackBtn.addEventListener('click', () => {
  const query = studentNameInput.value.trim();
  const cls = studentClassSelect.value;
  if (!query) {
    showStatus('Please enter your child\'s name to get started.', 'error');
    return;
  }
  startPolling({ studentName: query, studentClass: cls });
});

studentNameInput.addEventListener('keydown', (e) => {
  if (e.key === 'Enter') trackBtn.click();
});

newSearchBtn.addEventListener('click', resetToSearch);

if (toggleStopsBtn) {
  toggleStopsBtn.addEventListener('click', () => {
    showCheckpoints = !showCheckpoints;
    if (showCheckpoints) {
      checkpointsLayerGroup.addTo(map);
      toggleStopsBtn.classList.add('active');
    } else {
      map.removeLayer(checkpointsLayerGroup);
      toggleStopsBtn.classList.remove('active');
    }
  });
}

loadCheckpoints();

function refreshMapSize() {
  map.invalidateSize();
}
window.addEventListener('resize', refreshMapSize);
window.addEventListener('orientationchange', () => setTimeout(refreshMapSize, 250));
if (window.visualViewport) {
  window.visualViewport.addEventListener('resize', refreshMapSize);
}

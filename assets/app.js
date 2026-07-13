const map = L.map('map', { zoomControl: true }).setView([30.75, 76.78], 12); // default center, re-centers on data
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  attribution: '&copy; OpenStreetMap contributors',
}).addTo(map);

let parentLatLng = null;
let parentMarker = null;
let busMarker = null;
let routingControl = null;

let pollTimer = null;
let tickTimer = null;
let lastRouteUpdate = 0;
let lastFetchAt = null; // Date.now() of the last successful update, for the "Updated Xs ago" label

const ROUTE_REFRESH_MS = 45000; // recompute road route every 45s
const POLL_MS = 8000;           // move bus marker every 8s

// --- Element references ---
const studentIdInput = document.getElementById('studentId');
const trackBtn = document.getElementById('trackBtn');
const newSearchBtn = document.getElementById('newSearchBtn');

const statusBanner = document.getElementById('statusBanner');
const statusIcon = document.getElementById('statusIcon');
const statusText = document.getElementById('statusText');

const emptyState = document.getElementById('emptyState');
const infoSheet = document.getElementById('infoSheet');
const studentNameEl = document.getElementById('studentName');
const proximityPill = document.getElementById('proximityPill');
const busNoEl = document.getElementById('busNo');
const lastUpdatedEl = document.getElementById('lastUpdated');

// Bus + parent map icons, styled to match the app (see style.css)
const busIcon = L.divIcon({
  className: 'bus-icon',
  html: '<div class="bus-icon-wrap">🚌</div>',
  iconSize: [40, 40],
  iconAnchor: [20, 20],
});

const parentIcon = L.divIcon({
  className: 'parent-icon',
  html: '<div class="parent-icon-wrap"></div>',
  iconSize: [22, 22],
  iconAnchor: [11, 11],
});

// --- Status banner helpers ---
function showStatus(message, kind = 'info') {
  statusBanner.classList.remove('hidden', 'status-error', 'status-success');
  if (kind === 'error') {
    statusBanner.classList.add('status-error');
    statusIcon.textContent = '⚠️';
  } else if (kind === 'success') {
    statusBanner.classList.add('status-success');
    statusIcon.textContent = '✅';
  } else {
    statusIcon.textContent = 'ℹ️';
  }
  statusText.textContent = message;
}

function hideStatus() {
  statusBanner.classList.add('hidden');
}

// --- Parent's own location, used only to draw the route line ---
function initParentLocation() {
  if (!navigator.geolocation) {
    return; // silently skip — the bus still tracks fine without it
  }
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      parentLatLng = [pos.coords.latitude, pos.coords.longitude];
      parentMarker = L.marker(parentLatLng, { icon: parentIcon, title: 'You' }).addTo(map);
      map.setView(parentLatLng, 13);
    },
    (err) => {
      console.warn('Location permission denied or unavailable:', err.message);
      // Not shown as an error to parents — this is optional, the bus still tracks.
    }
  );
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
    busMarker = L.marker(latLng, { icon: busIcon }).addTo(map);
    map.setView(latLng, 14);
  } else {
    busMarker.setLatLng(latLng);
  }
}

function updateRoute(lat, lng) {
  if (!parentLatLng) return; // no route without parent location

  const now = Date.now();
  const shouldRefreshRoute = now - lastRouteUpdate > ROUTE_REFRESH_MS;

  if (!routingControl) {
    routingControl = L.Routing.control({
      waypoints: [L.latLng(parentLatLng), L.latLng(lat, lng)],
      routeWhileDragging: false,
      addWaypoints: false,
      draggableWaypoints: false,
      show: false,
      createMarker: () => null, // we manage our own markers above
    }).addTo(map);
    lastRouteUpdate = now;
  } else if (shouldRefreshRoute) {
    routingControl.setWaypoints([L.latLng(parentLatLng), L.latLng(lat, lng)]);
    lastRouteUpdate = now;
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
async function fetchAndRender(studentId) {
  try {
    const res = await fetch('/api/track.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ student_id: studentId }),
    });
    const data = await res.json();

    if (!res.ok) {
      showStatus(friendlyError(data.error), 'error');
      stopPolling();
      return;
    }

    hideStatus();
    emptyState.classList.add('hidden');
    infoSheet.classList.remove('hidden');

    studentNameEl.textContent = data.student.name;
    busNoEl.textContent = data.bus.vehicleNo;

    lastFetchAt = Date.now();
    lastUpdatedEl.textContent = 'just now';
    startTicking();

    const { lat, lng } = data.location;
    if (lat == null || lng == null) {
      showStatus("We can't see this bus's location right now. It may be off-route or its tracker is offline — please try again shortly.", 'error');
      proximityPill.classList.add('pill-hidden');
      return;
    }

    updateBusMarker(lat, lng);
    updateRoute(lat, lng);

    proximityPill.classList.remove('pill-hidden');
    if (parentLatLng) {
      const dist = distanceMeters(parentLatLng[0], parentLatLng[1], lat, lng);
      if (dist < 300) {
        proximityPill.textContent = '🚌 Nearby!';
        proximityPill.classList.add('pill-nearby');
        showStatus('The bus is close by — it should arrive any moment.', 'success');
      } else {
        proximityPill.textContent = 'On the way';
        proximityPill.classList.remove('pill-nearby');
      }
    } else {
      proximityPill.textContent = 'On the way';
      proximityPill.classList.remove('pill-nearby');
    }
  } catch (err) {
    console.error(err);
    showStatus("We couldn't reach the tracker. Please check your internet connection and try again.", 'error');
  }
}

function friendlyError(rawError) {
  switch (rawError) {
    case 'student_id is required':
      return 'Please enter your child\u2019s Student ID.';
    case 'No student found with this ID':
      return "We couldn't find a student with that ID. Please check it and try again.";
    case 'No bus mapped to this student':
      return "This student isn't linked to a bus yet. Please contact your school office.";
    default:
      return "We're having trouble reaching the tracker. Please try again in a moment.";
  }
}

function startPolling(studentId) {
  stopPolling();
  showStatus('Looking for the bus…');
  fetchAndRender(studentId);
  pollTimer = setInterval(() => fetchAndRender(studentId), POLL_MS);
}

function stopPolling() {
  if (pollTimer) clearInterval(pollTimer);
  pollTimer = null;
}

function resetToSearch() {
  stopPolling();
  stopTicking();
  hideStatus();
  infoSheet.classList.add('hidden');
  emptyState.classList.remove('hidden');
  studentIdInput.value = '';
  studentIdInput.focus();
}

// --- Wire up controls ---
trackBtn.addEventListener('click', () => {
  const studentId = studentIdInput.value.trim();
  if (!studentId) {
    showStatus('Please enter your child\u2019s Student ID to get started.', 'error');
    return;
  }
  startPolling(studentId);
});

studentIdInput.addEventListener('keydown', (e) => {
  if (e.key === 'Enter') trackBtn.click();
});

newSearchBtn.addEventListener('click', resetToSearch);

initParentLocation();

// The map's container size changes with the flexbox layout (phone
// rotation, keyboard opening/closing, browser address bar hiding).
// Leaflet needs an explicit nudge to redraw itself correctly when that
// happens, or tiles can be misaligned or blank.
function refreshMapSize() {
  map.invalidateSize();
}
window.addEventListener('resize', refreshMapSize);
window.addEventListener('orientationchange', () => setTimeout(refreshMapSize, 250));
if (window.visualViewport) {
  window.visualViewport.addEventListener('resize', refreshMapSize);
}

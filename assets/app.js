const sidebar = document.querySelector('#sidebar');
const menuToggle = document.querySelector('#menu-toggle');
let hasUnsavedChanges = false;

menuToggle?.addEventListener('click', () => {
    const isOpen = sidebar?.classList.toggle('is-open') ?? false;
    menuToggle.setAttribute('aria-expanded', String(isOpen));
});

document.addEventListener('click', (event) => {
    if (!sidebar?.classList.contains('is-open')) return;
    if (sidebar.contains(event.target) || menuToggle?.contains(event.target)) return;
    sidebar.classList.remove('is-open');
    menuToggle?.setAttribute('aria-expanded', 'false');
});

document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
});

document.querySelectorAll('form').forEach((form) => {
    form.addEventListener('input', () => { hasUnsavedChanges = true; });
    form.addEventListener('change', () => { hasUnsavedChanges = true; });
});

const imageViewer = document.querySelector('#image-viewer');
const imageViewerContent = document.querySelector('#image-viewer-content');
const imageViewerCaption = document.querySelector('#image-viewer-caption');
if (imageViewer && imageViewerContent && imageViewerCaption) {
    const openImageViewer = (image) => {
        imageViewerContent.src = image.src;
        imageViewerContent.alt = image.alt;
        imageViewerCaption.textContent = image.alt;
        imageViewer.showModal();
    };

    document.addEventListener('click', (event) => {
        const image = event.target.closest('img.image-preview');
        if (!image) return;
        event.preventDefault();
        event.stopPropagation();
        openImageViewer(image);
    });

    document.addEventListener('keydown', (event) => {
        const image = event.target.closest('img.image-preview');
        if (!image || !['Enter', ' '].includes(event.key)) return;
        event.preventDefault();
        event.stopPropagation();
        openImageViewer(image);
    });

    imageViewer.querySelector('.image-viewer-close').addEventListener('click', () => imageViewer.close());
    imageViewer.addEventListener('click', (event) => {
        if (event.target === imageViewer) imageViewer.close();
    });
    imageViewer.addEventListener('close', () => {
        imageViewerContent.removeAttribute('src');
        imageViewerCaption.textContent = '';
    });
}

const mapElement = document.querySelector('#location-map');
if (mapElement && window.L) {
    const map = L.map(mapElement, {
        maxBounds: [[-85.05112878, -180], [85.05112878, 180]],
        maxBoundsViscosity: 1,
        worldCopyJump: true,
    }).setView([20, 0], 2);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);

    const latitudeInput = document.querySelector('#location-latitude');
    const longitudeInput = document.querySelector('#location-longitude');
    const locationSelect = document.querySelector('#road-location-select');
    const pinStatus = document.querySelector('#map-pin-status');
    const googleMapsLink = document.querySelector('#google-maps-link');
    let marker;
    let locationWasChosen = false;

    const setPin = (latitude, longitude, zoom = true) => {
        const point = [Number(latitude), Number(longitude)];
        if (!Number.isFinite(point[0]) || !Number.isFinite(point[1]) || point[0] < -90 || point[0] > 90 || point[1] < -180 || point[1] > 180) return;
        latitudeInput.value = point[0].toFixed(7);
        longitudeInput.value = point[1].toFixed(7);
        if (marker) marker.setLatLng(point);
        else marker = L.marker(point).addTo(map);
        if (zoom) map.setView(point, 16);
        const coordinates = `${point[0].toFixed(7)},${point[1].toFixed(7)}`;
        pinStatus.textContent = coordinates;
        googleMapsLink.href = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(coordinates)}`;
        googleMapsLink.hidden = false;
        hasUnsavedChanges = true;
    };

    map.on('click', (event) => {
        locationWasChosen = true;
        locationSelect.value = '';
        setPin(event.latlng.lat, event.latlng.lng, false);
    });

    locationSelect.addEventListener('change', () => {
        locationWasChosen = true;
        const option = locationSelect.selectedOptions[0];
        const latitude = option?.dataset.latitude;
        const longitude = option?.dataset.longitude;
        if (latitude && longitude) setPin(latitude, longitude);
    });

    [latitudeInput, longitudeInput].forEach((input) => {
        input.addEventListener('change', () => {
            locationWasChosen = true;
            if (latitudeInput.value && longitudeInput.value) {
                locationSelect.value = '';
                setPin(latitudeInput.value, longitudeInput.value);
            }
        });
    });

    const firstLocation = Array.from(locationSelect.options).find((option) => (
        option.dataset.latitude && option.dataset.longitude
    ));
    if (firstLocation?.dataset.latitude && firstLocation.dataset.longitude) {
        const point = [Number(firstLocation.dataset.latitude), Number(firstLocation.dataset.longitude)];
        if (point.every(Number.isFinite)) map.setView(point, 13);
    }
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            (position) => {
                if (locationWasChosen) return;
                locationWasChosen = true;
                locationSelect.value = '';
                setPin(position.coords.latitude, position.coords.longitude);
                pinStatus.textContent = `${position.coords.latitude.toFixed(5)}, ${position.coords.longitude.toFixed(5)} · Current location`;
            },
            () => {
                if (locationWasChosen) return;
                pinStatus.textContent = firstLocation
                    ? 'Could not access your location. Choose a saved location or pin the map.'
                    : 'Could not access your location. Pin the map or enter coordinates.';
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 },
        );
    } else {
        pinStatus.textContent = 'Location is unavailable in this browser. Pin the map or enter coordinates.';
    }
    window.setTimeout(() => map.invalidateSize(), 100);
}

const hazardCategory = document.querySelector('#hazard-category-select');
const otherHazardInput = document.querySelector('#hazard-category-other');
if (hazardCategory && otherHazardInput) {
    const updateOtherHazardField = () => {
        const isOther = hazardCategory.value === 'Other road hazard';
        otherHazardInput.hidden = !isOther;
        otherHazardInput.required = isOther;
        if (!isOther) otherHazardInput.value = '';
    };
    hazardCategory.addEventListener('change', updateOtherHazardField);
    updateOtherHazardField();
}

if (document.body.dataset.refresh === 'true') {
    window.setTimeout(() => {
        if (!hasUnsavedChanges && document.visibilityState === 'visible') window.location.reload();
    }, 60_000);
}
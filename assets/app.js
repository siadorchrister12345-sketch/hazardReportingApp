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
    let marker;

    const setPin = (latitude, longitude, zoom = true) => {
        const point = [Number(latitude), Number(longitude)];
        if (!Number.isFinite(point[0]) || !Number.isFinite(point[1])) return;
        latitudeInput.value = point[0].toFixed(7);
        longitudeInput.value = point[1].toFixed(7);
        if (marker) marker.setLatLng(point);
        else marker = L.marker(point).addTo(map);
        if (zoom) map.setView(point, 16);
        pinStatus.textContent = `${point[0].toFixed(5)}, ${point[1].toFixed(5)}`;
        hasUnsavedChanges = true;
    };

    map.on('click', (event) => {
        locationSelect.value = '';
        setPin(event.latlng.lat, event.latlng.lng, false);
    });

    locationSelect.addEventListener('change', () => {
        const option = locationSelect.selectedOptions[0];
        const latitude = option?.dataset.latitude;
        const longitude = option?.dataset.longitude;
        if (latitude && longitude) setPin(latitude, longitude);
    });

    [latitudeInput, longitudeInput].forEach((input) => {
        input.addEventListener('change', () => {
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
    window.setTimeout(() => map.invalidateSize(), 100);
}

if (document.body.dataset.refresh === 'true') {
    window.setTimeout(() => {
        if (!hasUnsavedChanges && document.visibilityState === 'visible') window.location.reload();
    }, 60_000);
}
const sidebar = document.querySelector('#sidebar');
const menuToggle = document.querySelector('#menu-toggle');
const sidebarClose = document.querySelector('#sidebar-close');
let hasUnsavedChanges = false;

const deviceMemory = navigator.deviceMemory;
const logicalProcessors = navigator.hardwareConcurrency;
// Avoid continuous decorative effects when the browser reports limited resources.
if ((Number.isFinite(deviceMemory) && deviceMemory <= 4)
    || (Number.isFinite(logicalProcessors) && logicalProcessors <= 4)) {
    document.documentElement.classList.add('low-power-device');
}

const closeSidebar = () => {
    sidebar?.classList.remove('is-open');
    document.body.classList.remove('sidebar-open');
    menuToggle?.setAttribute('aria-expanded', 'false');
    menuToggle?.setAttribute('aria-label', 'Open navigation');
};

menuToggle?.addEventListener('click', () => {
    const isOpen = sidebar?.classList.toggle('is-open') ?? false;
    document.body.classList.toggle('sidebar-open', isOpen);
    menuToggle.setAttribute('aria-expanded', String(isOpen));
    menuToggle.setAttribute('aria-label', isOpen ? 'Close navigation' : 'Open navigation');
});

sidebarClose?.addEventListener('click', closeSidebar);

document.querySelectorAll('.job-map-toggle, .report-map-toggle').forEach((toggle) => {
    toggle.addEventListener('click', () => {
        const panel = toggle.closest('.job-map-panel, .report-map-panel');
        if (!panel) return;
        const isOpen = panel.classList.toggle('is-open');
        if (isOpen) {
            const iframe = panel.querySelector('iframe[data-src]');
            if (iframe && !iframe.hasAttribute('src')) iframe.src = iframe.dataset.src;
        }
        toggle.setAttribute('aria-expanded', String(isOpen));
        toggle.textContent = isOpen ? 'Hide location map' : 'Show location map';
    });
});

document.addEventListener('click', (event) => {
    if (!sidebar?.classList.contains('is-open')) return;
    if (sidebar.contains(event.target) || menuToggle?.contains(event.target)) return;
    closeSidebar();
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && sidebar?.classList.contains('is-open')) closeSidebar();
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

const registrationForm = document.querySelector('[data-registration-form]');
if (registrationForm) {
    const password = registrationForm.querySelector('input[name="password"]');
    const confirmation = registrationForm.querySelector('[data-password-confirmation]');
    const requirementItems = registrationForm.querySelectorAll('[data-password-rule]');
    const passwordStatus = registrationForm.querySelector('[data-password-status]');
    if (password && requirementItems.length && passwordStatus) {
        const passwordRules = {
            length: (value) => Array.from(value).length >= 10,
            uppercase: (value) => /[A-Z]/.test(value),
            lowercase: (value) => /[a-z]/.test(value),
            number: (value) => /[0-9]/.test(value),
            symbol: (value) => /[^A-Za-z0-9]/.test(value),
            spaces: (value) => value.length > 0 && !/\s/u.test(value),
        };
        const updatePasswordRequirements = () => {
            let metRequirements = 0;
            requirementItems.forEach((item) => {
                const rule = passwordRules[item.dataset.passwordRule];
                const isMet = Boolean(rule?.(password.value));
                item.classList.toggle('is-met', isMet);
                item.querySelector('span').textContent = isMet ? '✓' : '○';
                if (isMet) metRequirements += 1;
            });
            passwordStatus.textContent = metRequirements === requirementItems.length
                ? 'Password meets all requirements.'
                : `${metRequirements} of ${requirementItems.length} password requirements met.`;
        };
        password.addEventListener('input', updatePasswordRequirements);
        updatePasswordRequirements();
    }
    if (password && confirmation) {
        const validateConfirmation = () => {
            confirmation.setCustomValidity(
                confirmation.value && confirmation.value !== password.value
                    ? 'The password confirmation does not match.'
                    : '',
            );
        };
        password.addEventListener('input', validateConfirmation);
        confirmation.addEventListener('input', validateConfirmation);
        registrationForm.addEventListener('submit', validateConfirmation);
    }
}

document.querySelectorAll('[data-conversation-image]').forEach((input) => {
    const control = input.closest('.image-upload-control');
    const status = control?.querySelector('[data-attachment-status]');
    const preview = control?.querySelector('[data-attachment-preview]');
    if (!status || !preview) return;
    let previewUrl = null;
    const clearPreview = () => {
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        previewUrl = null;
        preview.removeAttribute('src');
        preview.hidden = true;
    };
    input.addEventListener('change', () => {
        const file = input.files?.[0];
        if (!file) {
            status.textContent = 'JPEG, PNG, or WebP · up to 5 MB. Select a photo, then press Send.';
            clearPreview();
            return;
        }
        const supportedType = ['image/jpeg', 'image/png', 'image/webp'].includes(file.type)
            || /\.(jpe?g|png|webp)$/i.test(file.name);
        if (!supportedType) {
            status.textContent = 'Choose a JPEG, PNG, or WebP image.';
            input.value = '';
            clearPreview();
            return;
        }
        if (file.size > 5 * 1024 * 1024) {
            status.textContent = 'This photo is larger than 5 MB. Choose a smaller image.';
            input.value = '';
            clearPreview();
            return;
        }
        status.textContent = `Selected: ${file.name} · press Send to attach`;
        clearPreview();
        previewUrl = URL.createObjectURL(file);
        preview.src = previewUrl;
        preview.hidden = false;
    });
});

document.querySelectorAll('form.conversation-compose').forEach((form) => {
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const submitButton = form.querySelector('button[type="submit"]');
        const status = form.querySelector('[data-message-status]');
        const conversationThread = form.closest('.conversation-content')?.querySelector('[data-live-report-id]');
        if (!submitButton || !status || !conversationThread) {
            console.error('Could not send message: the conversation form is incomplete.');
            return;
        }

        status.hidden = false;
        status.classList.remove('is-error');
        status.textContent = 'Sending…';
        submitButton.disabled = true;
        try {
            const response = await fetch('index.php', {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            const contentType = response.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                throw new Error(response.status === 401
                    ? 'Your session has expired. Sign in again to send this message.'
                    : 'The message could not be sent. Please reload the page and try again.');
            }
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'The message could not be sent.');

            const message = result.message;
            if (!conversationThread.querySelector(`[data-message-id="${message.id}"]`)) {
                conversationThread.querySelector('.conversation-empty')?.remove();
                const article = document.createElement('article');
                article.className = 'conversation-message is-mine';
                article.dataset.messageId = String(message.id);
                const meta = document.createElement('div');
                meta.className = 'conversation-message-meta';
                const sender = document.createElement('strong');
                sender.textContent = 'You';
                const date = document.createElement('span');
                date.textContent = message.display_date;
                meta.append(sender, date);
                const text = document.createElement('p');
                text.textContent = message.message;
                article.append(meta, text);
                if (message.photo_path) {
                    const image = document.createElement('img');
                    image.className = 'proof-image conversation-image image-preview';
                    image.src = `index.php?image=${encodeURIComponent(message.photo_path)}`;
                    image.alt = 'Conversation photo';
                    image.loading = 'lazy';
                    image.setAttribute('role', 'button');
                    image.tabIndex = 0;
                    image.setAttribute('aria-label', 'View full conversation photo');
                    article.append(image);
                }
                conversationThread.append(article);
                const count = form.closest('.conversation-disclosure')?.querySelector('[data-conversation-count]');
                if (count) count.textContent = String(Number(count.textContent || 0) + 1);
            }
            conversationThread.dataset.lastMessageId = String(message.id);
            conversationThread.scrollTop = conversationThread.scrollHeight;
            form.reset();
            form.querySelector('[data-conversation-image]')?.dispatchEvent(new Event('change', { bubbles: true }));
            status.textContent = 'Message sent.';
        } catch (error) {
            status.classList.add('is-error');
            status.textContent = error instanceof Error ? error.message : 'The message could not be sent.';
            console.error('Could not send conversation message.', error);
        } finally {
            submitButton.disabled = false;
        }
    });
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

const reportImageInput = document.querySelector('#report-image-input');
const openCameraButton = document.querySelector('#open-camera');
const cameraDialog = document.querySelector('#camera-dialog');
const cameraPreview = document.querySelector('#camera-preview');
const cameraStatus = document.querySelector('#camera-status');
const capturePhotoButton = document.querySelector('#capture-photo');
if (reportImageInput && openCameraButton && cameraDialog && cameraPreview && cameraStatus && capturePhotoButton) {
    let cameraStream;

    const stopCamera = () => {
        cameraStream?.getTracks().forEach((track) => track.stop());
        cameraStream = undefined;
        cameraPreview.srcObject = null;
        capturePhotoButton.disabled = true;
    };

    openCameraButton.addEventListener('click', async (event) => {
        event.preventDefault();
        event.stopPropagation();
        document.documentElement.classList.add('camera-open');
        cameraDialog.showModal();
        cameraStatus.textContent = 'Requesting permission to use your camera…';
        if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
            cameraStatus.textContent = 'Camera access is not available in this browser. Use HTTPS or localhost, or choose a photo file instead.';
            return;
        }

        try {
            cameraStream = await navigator.mediaDevices.getUserMedia({
                audio: false,
                video: { facingMode: { ideal: 'environment' } },
            });
            if (!cameraDialog.open) {
                stopCamera();
                return;
            }
            cameraPreview.srcObject = cameraStream;
            await cameraPreview.play();
            cameraStatus.textContent = 'Camera ready. Frame the hazard and take a photo.';
            capturePhotoButton.disabled = false;
        } catch (error) {
            stopCamera();
            cameraStatus.textContent = error instanceof DOMException && error.name === 'NotAllowedError'
                ? 'Camera permission was denied. Allow camera access in your browser settings, then try again.'
                : error instanceof DOMException && error.name === 'NotFoundError'
                    ? 'No camera was found. Choose an existing photo file instead.'
                    : 'The camera could not be opened. Check browser permissions and try again, or choose a photo file.';
        }
    });

    capturePhotoButton.addEventListener('click', () => {
        if (!cameraPreview.videoWidth || !cameraPreview.videoHeight) {
            cameraStatus.textContent = 'The camera is not ready yet. Wait a moment and try again.';
            return;
        }

        const scale = Math.min(1, 1920 / Math.max(cameraPreview.videoWidth, cameraPreview.videoHeight));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(cameraPreview.videoWidth * scale);
        canvas.height = Math.round(cameraPreview.videoHeight * scale);
        const context = canvas.getContext('2d');
        if (!context) {
            cameraStatus.textContent = 'Could not process the photo. Please try again or choose an image file.';
            return;
        }
        context.drawImage(cameraPreview, 0, 0, canvas.width, canvas.height);
        canvas.toBlob((blob) => {
            if (!blob) {
                cameraStatus.textContent = 'Could not save the photo. Please try again or choose an image file.';
                return;
            }
            if (blob.size > 5 * 1024 * 1024) {
                cameraStatus.textContent = 'The photo is larger than 5 MB. Try again or choose a smaller image.';
                return;
            }

            const transfer = new DataTransfer();
            transfer.items.add(new File([blob], 'hazard-photo.jpg', { type: 'image/jpeg' }));
            reportImageInput.files = transfer.files;
            reportImageInput.dispatchEvent(new Event('change', { bubbles: true }));
            cameraDialog.close();
        }, 'image/jpeg', 0.85);
    });

    document.querySelector('#camera-close').addEventListener('click', () => cameraDialog.close());
    cameraDialog.addEventListener('click', (event) => {
        if (event.target === cameraDialog) cameraDialog.close();
    });
    cameraDialog.addEventListener('close', () => {
        document.documentElement.classList.remove('camera-open');
        stopCamera();
    });
}

if (document.body.dataset.refresh === 'true') {
    const renderNotification = (notification) => {
        const row = document.createElement('article');
        row.className = `notification-row${notification.read_at ? '' : ' notification-unread'}`;
        row.dataset.notificationId = String(notification.id);
        row.dataset.notificationRead = notification.read_at ? 'true' : 'false';
        const link = document.createElement('a');
        link.className = 'notification-link';
        link.href = notification.report_url;
        const symbol = document.createElement('span');
        symbol.className = 'notification-symbol';
        symbol.textContent = notification.read_at ? '·' : '!';
        const content = document.createElement('span');
        const message = document.createElement('strong');
        message.textContent = notification.message;
        const details = document.createElement('small');
        details.textContent = `${notification.title} · ${notification.display_date}`;
        content.append(message, details);
        if (notification.photo_path) {
            const image = document.createElement('img');
            image.className = 'activity-image image-preview';
            image.src = `index.php?image=${encodeURIComponent(notification.photo_path)}`;
            image.alt = 'Progress photo';
            image.loading = 'lazy';
            image.setAttribute('role', 'button');
            image.tabIndex = 0;
            image.setAttribute('aria-label', 'View full progress photo');
            content.append(image);
        }
        const arrow = document.createElement('b');
        arrow.textContent = '↗';
        link.append(symbol, content, arrow);
        row.append(link);
        if (notification.read_at) {
            const readStatus = document.createElement('span');
            readStatus.className = 'notification-read-status';
            readStatus.textContent = 'Read';
            row.append(readStatus);
        } else {
            const csrfInput = document.querySelector('#mark-notifications-read input[name="csrf_token"]');
            if (!csrfInput) throw new Error('Notification read controls are missing a CSRF token.');
            const form = document.createElement('form');
            form.method = 'post';
            form.className = 'notification-read-form';
            const csrf = document.createElement('input');
            csrf.type = 'hidden';
            csrf.name = 'csrf_token';
            csrf.value = csrfInput.value;
            const action = document.createElement('input');
            action.type = 'hidden';
            action.name = 'action';
            action.value = 'mark_notification_read';
            const id = document.createElement('input');
            id.type = 'hidden';
            id.name = 'notification_id';
            id.value = String(notification.id);
            const button = document.createElement('button');
            button.className = 'button button-small button-quiet';
            button.type = 'submit';
            button.textContent = 'Mark read';
            form.append(csrf, action, id, button);
            row.append(form);
        }
        return row;
    };

    const updateNotifications = (notifications, unreadCount) => {
        document.querySelectorAll('[data-unread-indicator]').forEach((indicator) => {
            indicator.hidden = unreadCount === 0;
        });
        const count = document.querySelector('[data-notification-count]');
        if (count) {
            count.textContent = unreadCount ? `(${unreadCount.toLocaleString()})` : '';
            count.hidden = unreadCount === 0;
        }
        const nav = document.querySelector('[data-notification-nav]');
        nav?.setAttribute('aria-label', unreadCount ? `Notifications, ${unreadCount} unread` : 'Notifications');
        const readButton = document.querySelector('#mark-notifications-read');
        if (readButton) readButton.hidden = unreadCount === 0;

        const feed = document.querySelector('#notification-feed');
        if (!feed) return;
        if (!notifications.length) {
            if (feed.querySelector('.empty-state')) return;
            const empty = document.createElement('div');
            empty.className = 'empty-state';
            const icon = document.createElement('span');
            icon.className = 'empty-icon';
            icon.textContent = '◉';
            const heading = document.createElement('strong');
            heading.textContent = 'You are all caught up';
            const copy = document.createElement('p');
            copy.textContent = 'Updates about your reports and work will appear here.';
            empty.append(icon, heading, copy);
            feed.replaceChildren(empty);
            return;
        }
        const currentRows = Array.from(feed.querySelectorAll('[data-notification-id]'));
        const currentSignature = currentRows.map((row) => `${row.dataset.notificationId}:${row.dataset.notificationRead}`).join(',');
        const nextSignature = notifications.map((notification) => `${notification.id}:${notification.read_at ? 'true' : 'false'}`).join(',');
        if (currentSignature === nextSignature) return;
        const list = document.createElement('div');
        list.className = 'notification-list';
        notifications.forEach((notification) => {
            const row = renderNotification(notification);
            list.append(row);
        });
        feed.replaceChildren(list);
    };

    const pollUpdates = async () => {
        if (document.visibilityState !== 'visible') return;
        try {
            const cursors = {};
            document.querySelectorAll('[data-live-report-id]').forEach((thread) => {
                cursors[thread.dataset.liveReportId] = Number(thread.dataset.lastMessageId || 0);
            });
            const parameters = new URLSearchParams({
                updates: '1',
                messages: JSON.stringify(cursors),
                dashboard: document.body.dataset.dashboard === 'true' ? '1' : '0',
            });
            const response = await fetch(`index.php?${parameters}`, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
                credentials: 'same-origin',
            });
            if (response.status === 401) {
                window.location.assign('index.php?view=login');
                return;
            }
            if (!response.ok) throw new Error(`Live update request failed (${response.status}).`);
            const updates = await response.json();
            const latestNotificationId = Number(document.body.dataset.latestNotificationId || 0);
            const newJobNotification = updates.notifications.find((notification) => (
                Number(notification.id) > latestNotificationId
                && typeof notification.report_url === 'string'
                && notification.report_url.startsWith('index.php?view=jobs')
            ));
            if (new URLSearchParams(window.location.search).get('view') === 'jobs' && newJobNotification) {
                window.location.assign(newJobNotification.report_url);
                return;
            }
            if (updates.dashboard_counts) {
                document.querySelectorAll('[data-dashboard-count]').forEach((count) => {
                    const value = updates.dashboard_counts[count.dataset.dashboardCount];
                    if (Number.isInteger(value)) count.textContent = value.toLocaleString();
                });
            }

            updates.messages.forEach((message) => {
                const thread = document.getElementById(`live-conversation-${message.report_id}`);
                if (!thread) return;
                if (thread.querySelector(`[data-message-id="${message.id}"]`)) {
                    thread.dataset.lastMessageId = String(message.id);
                    return;
                }
                const empty = thread.querySelector('.conversation-empty');
                empty?.remove();

                const article = document.createElement('article');
                article.className = `conversation-message${Number(message.sender_user_id) === Number(document.body.dataset.userId) ? ' is-mine' : ''}`;
                article.dataset.messageId = String(message.id);
                const meta = document.createElement('div');
                meta.className = 'conversation-message-meta';
                const sender = document.createElement('strong');
                sender.textContent = Number(message.sender_user_id) === Number(document.body.dataset.userId) ? 'You' : message.sender_name;
                const date = document.createElement('span');
                date.textContent = message.display_date;
                meta.append(sender, date);
                const text = document.createElement('p');
                text.textContent = message.message;
                article.append(meta, text);
                if (message.photo_path) {
                    const image = document.createElement('img');
                    image.className = 'proof-image conversation-image image-preview';
                    image.src = `index.php?image=${encodeURIComponent(message.photo_path)}`;
                    image.alt = 'Conversation photo';
                    image.loading = 'lazy';
                    image.setAttribute('role', 'button');
                    image.tabIndex = 0;
                    image.setAttribute('aria-label', 'View full conversation photo');
                    article.append(image);
                }
                thread.append(article);
                thread.dataset.lastMessageId = String(message.id);
                const count = thread.closest('.conversation-disclosure')?.querySelector('[data-conversation-count]');
                if (count) count.textContent = String(Number(count.textContent || 0) + 1);
                thread.scrollTop = thread.scrollHeight;
            });
            updateNotifications(updates.notifications, updates.unread_count);
        } catch (error) {
            console.error('Could not refresh live messages and notifications.', error);
        }
    };

    const pollAndReschedule = async () => {
        if (document.visibilityState === 'visible') await pollUpdates();
        window.setTimeout(pollAndReschedule, 15_000);
    };
    window.setTimeout(pollAndReschedule, 10_000);
}
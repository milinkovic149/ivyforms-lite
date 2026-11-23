/**
 * IvyForms Gutenberg Block
 *
 * Block for embedding IvyForms in Gutenberg editor
 */

(function (wp) {
    const { registerBlockType } = wp.blocks;
    const { InspectorControls, BlockControls, useBlockProps } = wp.blockEditor || wp.editor;
    const { PanelBody, SelectControl, ToggleControl, TextControl, Placeholder, ToolbarGroup, ToolbarButton } = wp.components;
    const { __ } = wp.i18n;
    const ServerSideRender = wp.serverSideRender || wp.components.ServerSideRender;
    const { createElement, useEffect, useMemo, useRef, useState } = wp.element;

    // The Vue style panel bundle is a module (deferred), so it can register itself after the first
    // block render. Kept in sync with the event dispatched in style-panel.ts.
    const STYLE_PANEL_READY_EVENT = 'ivyforms/gutenberg/style-panel-ready';

    const getBootstrapConfig = function () {
        return window.ivyFormsGutenberg || {};
    };

    /**
     * Editor payload.
     *
     * Block styles flag off: PHP prints the form list inline, so the store starts out ready and
     * nothing is fetched. Flag on: PHP prints only a tiny bootstrap object, so a post or site
     * editor without IvyForms blocks pays nothing for the form list, the style panel labels or the
     * Vue panel bundle. The first block that renders on the canvas pulls all of it in once, and
     * every other block instance shares the result.
     */
    const inlineForms = getBootstrapConfig().forms;
    const editorDataStore = {
        status: Array.isArray(inlineForms) ? 'ready' : 'idle', // 'idle' | 'loading' | 'ready' | 'error'
        forms: Array.isArray(inlineForms) ? inlineForms : [],
        listeners: []
    };

    const notifyEditorDataListeners = function () {
        editorDataStore.listeners.slice().forEach(function (listener) {
            listener();
        });
    };

    /**
     * Injects the style panel stylesheet, which PHP no longer enqueues.
     */
    const injectStylePanelStyle = function (href) {
        if (!href || document.querySelector('link[data-ivyforms-style-panel]')) {
            return;
        }

        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        link.setAttribute('data-ivyforms-style-panel', '');
        document.head.appendChild(link);
    };

    /**
     * Injects the style panel bundle. It is an ES module, so it needs `type="module"` — the same
     * attribute PHP used to add for the enqueued handle. The bridge itself is picked up through
     * STYLE_PANEL_READY_EVENT, so nothing here depends on the load event.
     */
    const injectStylePanelScript = function (src) {
        if (!src || window.IvyFormsGutenbergStylePanel) {
            return;
        }

        if (document.querySelector('script[data-ivyforms-style-panel]')) {
            return;
        }

        const script = document.createElement('script');
        script.type = 'module';
        script.src = src;
        script.setAttribute('data-ivyforms-style-panel', '');
        document.head.appendChild(script);
    };

    /**
     * Fetches the editor payload once, then loads the style panel assets.
     *
     * The panel reads `wpIvyGutenbergStyleLabels` / `wpIvyGutenbergStyleUrls` when it mounts, so
     * both globals are filled in before the bundle is injected. `wpIvyGutenbergStyleUrls` is only
     * seeded when unset, mirroring how the panel avoids clobbering the public bundle's own URLs.
     */
    const loadEditorData = function () {
        if (editorDataStore.status !== 'idle') {
            return;
        }

        const config = getBootstrapConfig();
        const apiFetch = wp.apiFetch;

        if (!apiFetch || !config.editorDataPath) {
            editorDataStore.status = 'error';
            return;
        }

        editorDataStore.status = 'loading';

        apiFetch({ path: config.editorDataPath }).then(function (response) {
            const payload = (response && response.data) || {};

            window.wpIvyGutenbergStyleLabels = payload.labels || {};
            if (!window.wpIvyGutenbergStyleUrls) {
                window.wpIvyGutenbergStyleUrls = payload.urls || {};
            }

            editorDataStore.forms = payload.forms || [];
            editorDataStore.status = 'ready';
            notifyEditorDataListeners();

            const stylePanel = config.stylePanel || {};
            injectStylePanelStyle(stylePanel.style);
            injectStylePanelScript(stylePanel.script);
        }).catch(function () {
            editorDataStore.status = 'error';
            notifyEditorDataListeners();
        });
    };

    /**
     * Subscribes a block to the deferred payload and triggers the load on first use.
     *
     * `enabled` is false for the inserter preview card, so browsing the inserter never pulls the
     * payload in.
     */
    const useEditorData = function (enabled) {
        const [snapshot, setSnapshot] = useState(function () {
            return { status: editorDataStore.status, forms: editorDataStore.forms };
        });

        useEffect(function () {
            if (!enabled) {
                return undefined;
            }

            const listener = function () {
                setSnapshot(function (current) {
                    // Same payload: return the previous object so React skips the re-render.
                    if (current.status === editorDataStore.status && current.forms === editorDataStore.forms) {
                        return current;
                    }

                    return { status: editorDataStore.status, forms: editorDataStore.forms };
                });
            };

            editorDataStore.listeners.push(listener);
            loadEditorData();
            // Pick up a payload that arrived before this block mounted.
            listener();

            return function () {
                const index = editorDataStore.listeners.indexOf(listener);
                if (index !== -1) {
                    editorDataStore.listeners.splice(index, 1);
                }
            };
        }, [enabled]);

        return snapshot;
    };

    /**
     * Reads a label PHP printed inline with the bootstrap data (`BackendStrings`, so they are
     * translated like the rest of the plugin). Only present while the block styles flag is on,
     * which is the only state that shows them.
     */
    const getBootstrapLabel = function (key) {
        const labels = getBootstrapConfig().labels;

        return (labels && labels[key]) || '';
    };

    /**
     * Re-renders the block once the Vue style panel bundle has registered its bridge.
     */
    const useStylePanelBridge = function () {
        const [bridge, setBridge] = useState(function () {
            return window.IvyFormsGutenbergStylePanel || null;
        });

        useEffect(function () {
            if (bridge) {
                return undefined;
            }

            const onReady = function () {
                setBridge(window.IvyFormsGutenbergStylePanel || null);
            };

            window.addEventListener(STYLE_PANEL_READY_EVENT, onReady);

            return function () {
                window.removeEventListener(STYLE_PANEL_READY_EVENT, onReady);
            };
        }, [bridge]);

        return bridge;
    };

    /**
     * Mounts the Vue style panel (Quick style controls) into the block inspector sidebar.
     *
     * The panel reports style changes twice: instantly for the editor preview, and debounced for
     * the block attributes, so dragging a color picker never writes to the document history or
     * triggers a ServerSideRender request.
     */
    const StylePanelMount = function (props) {
        const containerRef = useRef(null);
        const controllerRef = useRef(null);
        const latestPropsRef = useRef(props);
        latestPropsRef.current = props;
        const bridge = props.bridge;

        useEffect(function () {
            if (!bridge || !containerRef.current) {
                return undefined;
            }

            controllerRef.current = bridge.mount(containerRef.current, {
                formId: latestPropsRef.current.formId,
                formStyleSettings: latestPropsRef.current.formStyleSettings,
                styleOverrides: latestPropsRef.current.styleOverrides,
                formType: latestPropsRef.current.formType,
                scopePrefix: latestPropsRef.current.scopePrefix,
                teleportScopeClass: latestPropsRef.current.teleportScopeClass,
                onPreview: function (css) {
                    latestPropsRef.current.onPreview(css);
                },
                onChange: function (styleOverrides) {
                    latestPropsRef.current.onChange(styleOverrides);
                }
            });

            return function () {
                if (controllerRef.current) {
                    controllerRef.current.unmount();
                    controllerRef.current = null;
                }
                // Hand styling back to the committed attributes once the block is deselected.
                latestPropsRef.current.onPreview(null);
            };
        }, [bridge]);

        useEffect(function () {
            if (controllerRef.current) {
                controllerRef.current.update({
                    formId: props.formId,
                    formStyleSettings: props.formStyleSettings,
                    styleOverrides: props.styleOverrides,
                    formType: props.formType,
                    scopePrefix: props.scopePrefix,
                    teleportScopeClass: props.teleportScopeClass
                });
            }
        }, [props.formId, props.formStyleSettings, props.styleOverrides, props.formType, props.scopePrefix, props.teleportScopeClass]);

        return createElement('div', {
            ref: containerRef,
            className: 'ivyforms-block-style-panel-mount'
        });
    };


    // Reusable IvyForms SVG icon element (used for block icon and placeholder)
    const ivyIcon = wp.element.createElement(
        'svg',
        { width: 32, height: 28, viewBox: '0 0 32 28', fill: 'none', xmlns: 'http://www.w3.org/2000/svg' },
        wp.element.createElement('path', {
            d: 'M17.7535 11.6827C17.7535 7.80295 18.7378 4.99706 20.7064 3.26503C22.7268 1.53301 25.9647 0.666992 30.4199 0.666992H32V10.4876C32 18.5415 29.7033 23.703 25.1099 25.9719C23.5039 26.7687 21.5785 27.167 19.3336 27.167H17.7535V11.6827ZM12.6405 27.167C8.30612 27.167 5.07692 25.6515 2.9529 22.6205C0.984302 19.8146 0 15.7703 0 10.4876V0.666992H1.58006C6.03532 0.666992 9.27316 1.53301 11.2936 3.26503C13.2622 4.99706 14.2465 7.80295 14.2465 11.6827V27.167H12.6405Z',
            fill: '#43D9B8'
        }),
        wp.element.createElement(
            'g',
            { style: { mixBlendMode: 'luminosity' }, opacity: 0.6 },
            wp.element.createElement('path', {
                d: 'M18.3944 6.95703C17.9667 8.29881 17.7529 9.87388 17.7529 11.6823V27.1666H19.3329C21.5778 27.1666 23.5033 26.7682 25.1092 25.9715C28.7913 24.1527 30.9975 20.4754 31.7281 14.9395C29.0408 10.6787 24.1488 7.62722 18.3944 6.95703Z',
                fill: 'white'
            }),
            wp.element.createElement('path', {
                d: 'M13.6043 6.95703C14.032 8.29881 14.2458 9.87388 14.2458 11.6823V27.1666H12.6398C8.30546 27.1666 5.07626 25.6511 2.95224 22.62C1.57912 20.6629 0.684889 18.1033 0.269531 14.9411C2.95665 10.6795 7.84915 7.62731 13.6043 6.95703Z',
                fill: 'white'
            })
        ),
        wp.element.createElement('path', {
            d: 'M12.0154 15.8365C12.8872 18.9673 12.5705 22.8186 9.89874 22.9527C6.75803 23.1103 3.66135 21.8552 2.69194 18.9796C1.72254 16.104 3.02381 13.0693 5.5984 12.2014C8.17299 11.3334 11.201 12.9119 12.0154 15.8365Z',
            fill: '#03455A'
        }),
        wp.element.createElement('path', {
            d: 'M12.3907 16.8495C13.2079 19.7614 12.9647 23.3285 10.5648 23.4241C7.74364 23.5364 4.94685 22.3395 4.04461 19.6631C3.14238 16.9868 4.2793 14.1873 6.58399 13.4104C8.88869 12.6334 11.6272 14.1294 12.3907 16.8495Z',
            fill: 'white'
        }),
        wp.element.createElement('path', {
            d: 'M12.611 21.5023C12.9649 20.1964 12.8334 18.4269 12.3907 16.8495C12.3711 16.7798 12.3502 16.7109 12.3281 16.6428C11.7101 16.0806 10.8889 15.7379 9.98766 15.7379C8.06619 15.7379 6.50853 17.2956 6.50853 19.217C6.50853 21.1385 8.06619 22.6962 9.98766 22.6962C11.0344 22.6962 11.9732 22.2339 12.611 21.5023Z',
            fill: '#03455A'
        }),
        wp.element.createElement('path', {
            d: 'M6.89128 17.8014C6.89128 17.0425 7.50646 16.4273 8.26533 16.4273C9.0242 16.4273 9.63938 17.0425 9.63938 17.8014C9.63938 18.5603 9.0242 19.1755 8.26533 19.1755C7.50646 19.1755 6.89128 18.5603 6.89128 17.8014Z',
            fill: 'white'
        }),
        wp.element.createElement('path', {
            d: 'M20.1511 15.8366C19.2793 18.9673 19.596 22.8187 22.2678 22.9527C25.4085 23.1103 28.5052 21.8552 29.4746 18.9796C30.444 16.104 29.1427 13.0693 26.5681 12.2014C23.9935 11.3335 20.9655 12.9119 20.1511 15.8366Z',
            fill: '#03455A'
        }),
        wp.element.createElement('path', {
            d: 'M19.7757 16.8496C18.9585 19.7615 19.2017 23.3286 21.6016 23.4241C24.4228 23.5364 27.2195 22.3395 28.1218 19.6632C29.024 16.9868 27.8871 14.1874 25.5824 13.4104C23.2777 12.6335 20.5392 14.1294 19.7757 16.8496Z',
            fill: 'white'
        }),
        wp.element.createElement('path', {
            d: 'M19.5554 21.5024C19.2015 20.1965 19.333 18.4269 19.7757 16.8496C19.7953 16.7799 19.8162 16.7109 19.8383 16.6428C20.4563 16.0806 21.2775 15.7379 22.1787 15.7379C24.1002 15.7379 25.6579 17.2956 25.6579 19.2171C25.6579 21.1385 24.1002 22.6962 22.1787 22.6962C21.132 22.6962 20.1932 22.2339 19.5554 21.5024Z',
            fill: '#03455A'
        }),
        wp.element.createElement('path', {
            d: 'M25.2799 17.7881C25.2799 17.0218 24.6588 16.4007 23.8925 16.4007C23.1263 16.4007 22.5051 17.0218 22.5051 17.7881C22.5051 18.5543 23.1263 19.1755 23.8925 19.1755C24.6588 19.1755 25.2799 18.5543 25.2799 17.7881Z',
            fill: 'white'
        })
    );


    registerBlockType('ivyforms/gutenberg-block', {
        title: __('IvyForms', 'ivyforms'),
        description: __('Embed an IvyForms Form', 'ivyforms'),
        category: 'ivyforms',
        icon: {
            src: ivyIcon
        },
        keywords: [
            __('form', 'ivyforms'),
            __('contact', 'ivyforms'),
            __('ivy', 'ivyforms'),
        ],
        attributes: {
            formId: {
                type: 'string',
                default: '',
            },
            showTitle: {
                type: 'boolean',
                default: true,
            },
            showDescription: {
                type: 'boolean',
                default: true,
            },
            customCssClass: {
                type: 'string',
                default: '',
            },
            className: {
                type: 'string',
                default: '',
            },
            // Sparse map of the Quick style properties overridden for this block instance only.
            styleOverrides: {
                type: 'object',
                default: {},
            },
            preview: {
                type: 'boolean',
                default: false,
            },
        },
        supports: {
            customClassName: true,
            html: false,
        },

        edit: function (props) {
            const { attributes, setAttributes, clientId } = props;
            const { formId, showTitle, showDescription, customCssClass, styleOverrides, preview } = attributes;

            // Use useBlockProps hook for proper block selection
            const blockProps = useBlockProps({
                className: 'ivyforms-block-wrapper'
            });

            const instanceSelector = '[data-ivyforms-instance="' + clientId + '"]';
            // The previewed form adds this class to its popper roots (select, date and time
            // panels render outside the wrapper), so the instance CSS can reach them.
            const teleportScopeClass = 'ivyforms-teleport-' + clientId;

            // ServerSideRender only receives the attributes that change the markup. Style is pure
            // CSS and generated client-side, so style edits cost zero REST requests.
            const serverRenderAttributes = useMemo(function () {
                return {
                    formId: formId,
                    showTitle: showTitle,
                    showDescription: showDescription,
                    customCssClass: customCssClass
                };
            }, [formId, showTitle, showDescription, customCssClass]);

            // PHP leaves `stylePanel` null while the `gutenberg-block-styles` flag is off.
            const stylePanelEnabled = !!getBootstrapConfig().stylePanel;

            // Instant preview CSS reported by the style panel; null means "use committed attributes".
            const [livePreviewCss, setLivePreviewCss] = useState(null);
            const stylePanelBridge = useStylePanelBridge();

            // Note: Form data sync is handled by MutationObserver in GutenbergBlock.php
            // This ensures wpIvyFormDataList is always up to date when ServerSideRender updates

            // Forms arrive with the deferred editor payload, pulled in by the first block on the
            // canvas. The inserter preview card never needs them.
            const editorData = useEditorData(!preview);
            const forms = editorData.forms;
            const isLoadingForms = editorData.status === 'idle' || editorData.status === 'loading';
            const formsLoadFailed = editorData.status === 'error';

            // Helper function to find form by ID
            const getFormById = function (id) {
                if (!id) {
                    return null;
                }
                return forms.find(function (f) {
                    return f.value === id; });
            };

            const selectedForm = getFormById(formId);
            const formStyleSettings = selectedForm ? selectedForm.styleSettings : null;
            const formType = selectedForm ? selectedForm.formType : undefined;

            // Instance CSS for the styles already stored in the attributes. Blocks that are not
            // selected have no mounted panel, so they still get their overrides from here.
            const committedInstanceCss = useMemo(function () {
                if (!stylePanelBridge || !formId) {
                    return '';
                }

                return stylePanelBridge.generateInstanceCss({
                    formId: formId,
                    formStyleSettings: formStyleSettings,
                    styleOverrides: styleOverrides,
                    formType: formType,
                    scopePrefix: instanceSelector,
                    teleportScopeClass: teleportScopeClass
                });
            }, [stylePanelBridge, formId, formStyleSettings, styleOverrides, formType, instanceSelector, teleportScopeClass]);

            const instanceCss = livePreviewCss === null ? committedInstanceCss : livePreviewCss;

            // Show static preview image when in block inserter preview mode
            if (preview) {
                return wp.element.createElement(
                    'div',
                    blockProps,
                    wp.element.createElement(
                        'div',
                        {
                            className: 'ivyforms-block-preview-image',
                            style: {
                                width: '100%',
                                maxWidth: '230px',
                                margin: '0 auto'
                            }
                        },
                        wp.element.createElement(
                            'svg',
                            {
                                xmlns: 'http://www.w3.org/2000/svg',
                                viewBox: '0 0 230 160',
                                width: '230',
                                height: '160',
                                style: { width: '100%', height: 'auto' }
                            },
                            // Background card
                            wp.element.createElement('rect', { width: '230', height: '160', fill: '#ffffff', rx: '4' }),
                            // Header bar with IvyForms brand color
                            wp.element.createElement('rect', { width: '230', height: '28', fill: '#43D9B8', rx: '4' }),
                            wp.element.createElement('rect', { y: '24', width: '230', height: '4', fill: '#43D9B8' }),
                            // Form title placeholder
                            wp.element.createElement('rect', { x: '12', y: '8', width: '80', height: '12', fill: 'white', opacity: '0.9', rx: '2' }),
                            // Field 1: Name
                            wp.element.createElement('rect', { x: '12', y: '38', width: '40', height: '6', fill: '#03455A', opacity: '0.7', rx: '1' }),
                            wp.element.createElement('rect', { x: '12', y: '48', width: '206', height: '20', fill: '#f3f4f6', stroke: '#e5e7eb', strokeWidth: '1', rx: '3' }),
                            // Field 2: Email
                            wp.element.createElement('rect', { x: '12', y: '76', width: '32', height: '6', fill: '#03455A', opacity: '0.7', rx: '1' }),
                            wp.element.createElement('rect', { x: '12', y: '86', width: '206', height: '20', fill: '#f3f4f6', stroke: '#e5e7eb', strokeWidth: '1', rx: '3' }),
                            // Field 3: Message
                            wp.element.createElement('rect', { x: '12', y: '114', width: '48', height: '6', fill: '#03455A', opacity: '0.7', rx: '1' }),
                            wp.element.createElement('rect', { x: '12', y: '124', width: '206', height: '24', fill: '#f3f4f6', stroke: '#e5e7eb', strokeWidth: '1', rx: '3' }),
                            // Submit button
                            wp.element.createElement('rect', { x: '168', y: '124', width: '50', height: '24', fill: '#43D9B8', rx: '3' }),
                            wp.element.createElement('rect', { x: '178', y: '133', width: '30', height: '6', fill: 'white', opacity: '0.9', rx: '1' })
                        )
                    )
                );
            }

            // Prepare form options for select
            const formOptions = [
                { value: '', label: __('Select a form', 'ivyforms') }
            ];

            forms.forEach(function (form) {
                formOptions.push({
                    value: form.value,
                    label: form.label
                });
            });

            // Check if no forms exist — only decidable once the payload has arrived
            const noFormsExist = !isLoadingForms && forms.length === 0;

            // Build the form builder URL
            const adminUrl = getBootstrapConfig().adminUrl;
            const formBuilderUrl = formId && adminUrl
                ? adminUrl + '#/manage/' + formId
                : '';

            // Handler for form selection change - syncs toggles from form settings
            const onFormChange = function (value) {
                // Overrides belong to the form they were made against, so a different form starts
                // from that form's styles again.
                const newAttributes = { formId: value };
                if (stylePanelEnabled) {
                    newAttributes.styleOverrides = {};
                }

                // Sync showTitle and showDescription from form settings when a form is selected
                if (value) {
                    const changedForm = getFormById(value);
                    if (changedForm) {
                        if (typeof changedForm.showTitle === 'boolean') {
                            newAttributes.showTitle = changedForm.showTitle;
                        }
                        if (typeof changedForm.showDescription === 'boolean') {
                            newAttributes.showDescription = changedForm.showDescription;
                        }
                    }
                }

                setLivePreviewCss(null);
                setAttributes(newAttributes);
            };


            return wp.element.createElement(
                'div',
                blockProps,
                // Block Controls (Toolbar)
                formId && wp.element.createElement(
                    BlockControls,
                    null,
                    wp.element.createElement(
                        ToolbarGroup,
                        null,
                        wp.element.createElement(
                            ToolbarButton,
                            {
                                icon: 'edit',
                                label: __('Customize Form', 'ivyforms'),
                                onClick: function () {
                                    if (formBuilderUrl) {
                                        window.open(formBuilderUrl, '_blank');
                                    }
                                }
                            }
                        )
                    )
                ),
                // Inspector Controls (Sidebar)
                wp.element.createElement(
                    InspectorControls,
                    null,
                    wp.element.createElement(
                        PanelBody,
                        {
                            title: __('Form Settings', 'ivyforms'),
                            initialOpen: true
                        },
                        wp.element.createElement(
                            SelectControl,
                            {
                                label: __('Select Form', 'ivyforms'),
                                value: formId,
                                options: formOptions,
                                onChange: onFormChange,
                                help: isLoadingForms ?
                                    getBootstrapLabel('block_loading_forms') :
                                    (formsLoadFailed ?
                                        getBootstrapLabel('block_forms_load_failed') :
                                        (noFormsExist ?
                                            __('No forms found. Please create a form first.', 'ivyforms') :
                                            __('Choose which form to display', 'ivyforms')))
                            }
                        ),
                        formId && wp.element.createElement(
                            ToggleControl,
                            {
                                label: __('Show Form Title', 'ivyforms'),
                                checked: showTitle,
                                onChange: function (value) {
                                    setAttributes({ showTitle: value });
                                }
                            }
                        ),
                        formId && wp.element.createElement(
                            ToggleControl,
                            {
                                label: __('Show Form Description', 'ivyforms'),
                                checked: showDescription,
                                onChange: function (value) {
                                    setAttributes({ showDescription: value });
                                }
                            }
                        ),
                        formId && wp.element.createElement(
                            TextControl,
                            {
                                label: __('Additional CSS Class(es)', 'ivyforms'),
                                value: customCssClass,
                                onChange: function (value) {
                                    setAttributes({ customCssClass: value });
                                },
                                help: __('Add custom CSS classes separated by spaces', 'ivyforms')
                            }
                        )
                    ),
                    stylePanelEnabled && formId && wp.element.createElement(
                        PanelBody,
                        {
                            title: getBootstrapLabel('block_form_style'),
                            initialOpen: false
                        },
                        wp.element.createElement(
                            StylePanelMount,
                            {
                                bridge: stylePanelBridge,
                                formId: formId,
                                formStyleSettings: formStyleSettings,
                                styleOverrides: styleOverrides,
                                formType: formType,
                                scopePrefix: instanceSelector,
                                teleportScopeClass: teleportScopeClass,
                                onPreview: setLivePreviewCss,
                                onChange: function (nextStyleOverrides) {
                                    setAttributes({ styleOverrides: nextStyleOverrides });
                                }
                            }
                        )
                    )
                ),
                // Block Content
                !formId ?
                    wp.element.createElement(
                        Placeholder,
                        {
                            icon: ivyIcon,
                            label: __('IvyForms', 'ivyforms'),
                            className: 'ivyforms-block-placeholder'
                        },
                        isLoadingForms ?
                            wp.element.createElement(
                                'p',
                                null,
                                getBootstrapLabel('block_loading_forms')
                            ) :
                            (formsLoadFailed ?
                                wp.element.createElement(
                                    'p',
                                    null,
                                    getBootstrapLabel('block_forms_load_failed')
                                ) :
                                (noFormsExist ?
                                    wp.element.createElement(
                                        'p',
                                        null,
                                        __('No forms found. Please create a form first.', 'ivyforms')
                                    ) :
                                    wp.element.createElement(
                                        'p',
                                        null,
                                        __('Select a form from the block settings on the right.', 'ivyforms')
                                    )))
                    ) :
                    wp.element.createElement(
                        'div',
                        {
                            className: 'ivyforms-gutenberg-block-preview' +
                                (!showTitle ? ' ivyforms-hide-title' : '') +
                                (!showDescription ? ' ivyforms-hide-description' : ''),
                            'data-ivyforms-instance': clientId,
                            'data-ivyforms-teleport-scope': stylePanelEnabled ? teleportScopeClass : undefined,
                            style: {
                                position: 'relative'
                            }
                        },
                        // Inline style element to hide title/description, plus the instance style
                        // layer. React renders it into the editor canvas document.
                        wp.element.createElement(
                            'style',
                            null,
                            '.ivyforms-hide-title .ivyforms-form-title { display: none !important; }' +
                            '.ivyforms-hide-description .ivyforms-form-description { display: none !important; }' +
                            instanceCss
                        ),
                        wp.element.createElement(
                            ServerSideRender,
                            {
                                block: 'ivyforms/gutenberg-block',
                                attributes: serverRenderAttributes
                            }
                        ),
                        wp.element.createElement(
                            'div',
                            {
                                className: 'ivyforms-block-overlay',
                                style: {
                                    position: 'absolute',
                                    top: 0,
                                    left: 0,
                                    right: 0,
                                    bottom: 0,
                                    zIndex: 1
                                }
                            }
                        )
                    )
            );
        },

        save: function () {
            // Server-side rendering, so return null
            return null;
        },
    });
})(window.wp);


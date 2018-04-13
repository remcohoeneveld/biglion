(function ($) {

    $.fn.searchplus = function (config) {

        if(typeof config == 'undefined') {
            config = {};
        }

        var $input = typeof config.input !== 'undefined' ? $(config.input) : $('#searchinput');
        var $searchform = typeof config.form !== 'undefined' ? $(config.form) : $('#searchform');
        var $results_container = typeof config.resultsContainer !== 'undefined' ? $(config.resultsContainer) : $('#results');
        var $pagination_container = typeof config.paginationContainer !== 'undefined' ? $(config.paginationContainer) : $('#pagination');

        // Replace with your own values
        var APPLICATION_ID = typeof config.applicationId !== 'undefined' ? config.applicationId : $searchform.attr('data-applicationId');
        var SEARCH_ONLY_API_KEY = typeof config.searchApiKey !== 'undefined' ? config.searchApiKey : $searchform.attr('data-searchApiKey');
        var INDEX_NAME = typeof config.index !== 'undefined' ? config.index : $searchform.attr('data-index');
        var PARAMS = {
            hitsPerPage: typeof config.hitsPerPage !== 'undefined' ? config.hitsPerPage : 25,
            maxValuesPerFacet: typeof config.maxValuesPerFacet !== 'undefined' ? config.maxValuesPerFacet : 10,
            facets: typeof config.facets !== 'undefined' ? config.facets : [],
            disjunctiveFacets:  typeof config.disjunctiveFacets !== 'undefined' ? config.disjunctiveFacets : []
        };
        var SEARCHONLOAD = typeof config.searchOnLoad !== 'undefined' ? config.searchOnLoad : true;
        var URLHistoryTimer = Date.now();
        var URLHistoryThreshold = 700;

        var FACETS_SLIDER = typeof config.facetsSlider !== 'undefined' ? config.facetsSlider : [];
        var FACETS_ORDER_OF_DISPLAY = typeof config.facetsOrderOfDisplay !== 'undefined' ? config.facetsOrderOfDisplay : PARAMS.facets.concat(PARAMS.disjunctiveFacets);
        var FACETS_LABELS = typeof config.facetsLabels !== 'undefined' ? config.facetsLabels : [];
        var FACETS_FORCED_REFINEMENT = typeof config.facetsForcedRefinement !== 'undefined' ? config.facetsForcedRefinement : [];

        // Client + Helper initialization
        var algolia = algoliasearch(APPLICATION_ID, SEARCH_ONLY_API_KEY);
        var algoliaHelper = algoliasearchHelper(algolia, INDEX_NAME, PARAMS);



        // DOM BINDING
        $pagination = $pagination_container;
        $hits = $results_container;
        $inputIcon = $('#search-input-icon');
        $main = $('main');
        $sortBySelect = $('#sort-by-select');
        $stats = $('#stats');
        $facets = $('#facets');

        var hitTemplate = Hogan.compile($('#hit-template').text());
        var statsTemplate = Hogan.compile($('#stats-template').text());
        var facetTemplate = Hogan.compile($('#facet-template').text());
        var paginationTemplate = Hogan.compile($('#pagination-template').text());
        var noResultsTemplate = Hogan.compile($('#no-results-template').text());
        var sliderTemplate = Hogan.compile($('#slider-template').text());

        // Input binding
        $input.on('keyup', function () {
            var query = $(this).val();
            algoliaHelper.setQuery(query).search();
        }).focus();

        // Update URL
        algoliaHelper.on('change', function(state) {
            setURLParams();
        });
        // Search results
        algoliaHelper.on('result', function (content, state) {
            renderHits(content);
            renderStats(content);
            renderPagination(content);
            renderFacets(content, state);
            bindSearchObjects(state);
            handleNoResults(content);
        });

        $sortBySelect.on('change', function(e) {
            e.preventDefault();
            algoliaHelper.setIndex(INDEX_NAME + $(this).val()).search();
        });

        // Initial search
        // As long as we don't have a searchOnLoad = false config
        if(SEARCHONLOAD == true) {
            initFromURLParams();
            algoliaHelper.search();
        }

        for (var a in FACETS_FORCED_REFINEMENT) {
            if(typeof FACETS_FORCED_REFINEMENT[a] != 'string') {
                for (var b in FACETS_FORCED_REFINEMENT[a]) {
                    algoliaHelper.addDisjunctiveFacetRefinement(a, FACETS_FORCED_REFINEMENT[a][b]);
                }
            } else {
                algoliaHelper.addDisjunctiveFacetRefinement(a, FACETS_FORCED_REFINEMENT[a]);
            }
        }

        function setURLParams() {
            var trackedParameters = ['attribute:*'];
            if (algoliaHelper.state.query.trim() !== '')  trackedParameters.push('query');
            if (algoliaHelper.state.page !== 0)           trackedParameters.push('page');
            if (algoliaHelper.state.index !== INDEX_NAME) trackedParameters.push('index');

            var URLParams = window.location.search.slice(1);
            var nonAlgoliaURLParams = algoliasearchHelper.AlgoliaSearchHelper.getForeignConfigurationInQueryString(URLParams);
            var nonAlgoliaURLHash = window.location.hash;
            var helperParams = algoliaHelper.getStateAsQueryString({filters: trackedParameters, moreAttributes: nonAlgoliaURLParams});
            if (URLParams === helperParams) return;

            var now = Date.now();
            if (URLHistoryTimer > now) {
                window.history.replaceState({searchplus: true}, '', '?' + helperParams + nonAlgoliaURLHash);
            } else {
                window.history.pushState({searchplus: true}, '', '?' + helperParams + nonAlgoliaURLHash);
            }
            URLHistoryTimer = now+URLHistoryThreshold;
        }

        function initFromURLParams() {
            var URLString = window.location.search.slice(1);
            var URLParams = algoliasearchHelper.AlgoliaSearchHelper.getConfigurationFromQueryString(URLString);
            if (URLParams.query) $input.val(URLParams.query);
            if (URLParams.index) $sortBySelect.val(URLParams.index.replace(INDEX_NAME, ''));
            algoliaHelper.overrideStateWithoutTriggeringChangeEvent(algoliaHelper.state.setQueryParameters(URLParams));
        }

        function bindSearchObjects(state) {
            // Bind Sliders
            for (facetIndex = 0; facetIndex < FACETS_SLIDER.length; ++facetIndex) {
                var facetName = FACETS_SLIDER[facetIndex];
                var slider = $('#' + facetName + '-slider');
                var sliderOptions = {
                    type: 'double',
                    grid: true,
                    min: slider.data('min'),
                    max: slider.data('max'),
                    from: slider.data('from'),
                    to: slider.data('to'),
                    prettify: function(num) {
                        return '£' + parseInt(num, 10);
                    },
                    onFinish: function(data) {
                        var lowerBound = state.getNumericRefinement(facetName, '>=');
                        lowerBound = lowerBound && lowerBound[0] || data.min;
                        if (data.from !== lowerBound) {
                            algoliaHelper.removeNumericRefinement(facetName, '>=');
                            algoliaHelper.addNumericRefinement(facetName, '>=', data.from).search();
                        }
                        var upperBound = state.getNumericRefinement(facetName, '<=');
                        upperBound = upperBound && upperBound[0] || data.max;
                        if (data.to !== upperBound) {
                            algoliaHelper.removeNumericRefinement(facetName, '<=');
                            algoliaHelper.addNumericRefinement(facetName, '<=', data.to).search();
                        }
                    }
                };
                slider.ionRangeSlider(sliderOptions);
            }
        }

        function renderHits(content) {
            $hits.html(hitTemplate.render(content));
            $hits.addClass('active');
        }

        function renderFacets(content, state) {
            var facetsHtml = '';
            for (var facetIndex = 0; facetIndex < FACETS_ORDER_OF_DISPLAY.length; ++facetIndex) {
                var facetName = FACETS_ORDER_OF_DISPLAY[facetIndex];
                var facetResult = content.getFacetByName(facetName);
                if (!facetResult) continue;
                var facetContent = {};

                // Slider facets
                if ($.inArray(facetName, FACETS_SLIDER) !== -1) {
                    facetContent = {
                        facet: facetName,
                        title: FACETS_LABELS[facetName]
                    };
                    facetContent.min = facetResult.stats.min;
                    facetContent.max = facetResult.stats.max;
                    var from = state.getNumericRefinement(facetName, '>=') || facetContent.min;
                    var to = state.getNumericRefinement(facetName, '<=') || facetContent.max;
                    facetContent.from = Math.min(facetContent.max, Math.max(facetContent.min, from));
                    facetContent.to = Math.min(facetContent.max, Math.max(facetContent.min, to));
                    facetsHtml += sliderTemplate.render(facetContent);
                }
                // Conjunctive + Disjunctive facets
                else {
                    facetContent = {
                        facet: facetName,
                        title: FACETS_LABELS[facetName],
                        values: content.getFacetValues(facetName, {sortBy: ['isRefined:desc', 'count:desc']}),
                        disjunctive: $.inArray(facetName, PARAMS.disjunctiveFacets) !== -1
                    };
                    facetsHtml += facetTemplate.render(facetContent);
                }
            }
            $facets.html(facetsHtml);
        }

        function renderStats(content) {
            var stats = {
                nbHits: content.nbHits,
                nbHits_plural: content.nbHits !== 1,
                processingTimeMS: content.processingTimeMS
            };
            $stats.html(statsTemplate.render(stats));
        }

        function handleNoResults(content) {
            if (content.nbHits > 0) {
                $main.removeClass('no-results');
                return;
            }

            $main.addClass('no-results');

            var filters = [];
            var i;
            var j;
            for (i in algoliaHelper.state.facetsRefinements) {
                filters.push({
                    class: 'toggle-refine',
                    facet: i, facet_value: algoliaHelper.state.facetsRefinements[i],
                    label: FACETS_LABELS[i] + ': ',
                    label_value: algoliaHelper.state.facetsRefinements[i]
                });
            }
            for (i in algoliaHelper.state.disjunctiveFacetsRefinements) {
                for (j in algoliaHelper.state.disjunctiveFacetsRefinements[i]) {
                    filters.push({
                        class: 'toggle-refine',
                        facet: i,
                        facet_value: algoliaHelper.state.disjunctiveFacetsRefinements[i][j],
                        label: FACETS_LABELS[i] + ': ',
                        label_value: algoliaHelper.state.disjunctiveFacetsRefinements[i][j]
                    });
                }
            }
            for (i in algoliaHelper.state.numericRefinements) {
                for (j in algoliaHelper.state.numericRefinements[i]) {
                    filters.push({
                        class: 'remove-numeric-refine',
                        facet: i,
                        facet_value: j,
                        label: FACETS_LABELS[i] + ' ',
                        label_value: j + ' ' + algoliaHelper.state.numericRefinements[i][j]
                    });
                }
            }
            $hits.html(noResultsTemplate.render({query: content.query, filters: filters}));
        }

        function renderPagination(content) {
            var pages = [];
            if (content.page > 3) {
                pages.push({current: false, number: 1});
                pages.push({current: false, number: '...', disabled: true});
            }
            for (var p = content.page - 3; p < content.page + 3; ++p) {
                if (p < 0 || p >= content.nbPages) continue;
                pages.push({current: content.page === p, number: p + 1});
            }
            if (content.page + 3 < content.nbPages) {
                pages.push({current: false, number: '...', disabled: true});
                pages.push({current: false, number: content.nbPages});
            }
            var pagination = {
                pages: pages,
                prev_page: content.page > 0 ? content.page : false,
                next_page: content.page + 1 < content.nbPages ? content.page + 2 : false
            };
            $pagination.html(paginationTemplate.render(pagination));
        }

        $(document).on('click', '.go-to-page', function (e) {
            e.preventDefault();
            $('html, body').animate({scrollTop: 0}, '500', 'swing');
            algoliaHelper.setCurrentPage(+$(this).data('page') - 1).search();
        });
        $(document).on('click', '.remove-numeric-refine', function (e) {
            e.preventDefault();
            algoliaHelper.removeNumericRefinement($(this).data('facet'), $(this).data('value')).search();
        });
        $(document).on('click', '.clear-all', function (e) {
            e.preventDefault();
            $input.val('').focus();
            algoliaHelper.setQuery('').clearRefinements().search();
        });
        $(document).on('click', '.toggle-refine', function(e) {
            e.preventDefault();
            algoliaHelper.toggleRefine($(this).data('facet'), $(this).data('value')).search();
        });
        window.addEventListener('popstate', function(e) {
            if(e.state == null) return;
            initFromURLParams();
            algoliaHelper.search();
        });
    }
}(jQuery));


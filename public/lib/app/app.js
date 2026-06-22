function ModalLoad(idmodal, title, path) {
	$("#" + idmodal + " .modal-header h4").text(title);
	$("#" + idmodal + " #framemodal").attr("src", path);
}

$(document).ready(function () {

	$("#selectproject").on("change", function () {
		url = $(this).data("change");

		$.ajax({
			type: "POST",
			url: url,
			data: {
				id: $(this).val()
			},
			success: function (result) {
				location.reload();
			}
		});

	});
});

$(document).ready(function () {
	$(document).on('select2:open', () => {
		setTimeout(() => {
			let input = document.querySelector('.select2-container--open .select2-search__field');
			if (input) input.focus();
		}, 0);
	});
});

$(document).ready(function () {
	$('.select2').select2({
		theme: 'bootstrap-5',
		templateResult: function (data) {
			if (!data.id) return data.text;

			const $result = $('<span>').text(data.text);

			const customClass = $(data.element).attr('class');
			if (customClass) {
				$result.addClass(customClass);
			}

			return $result;
		},
		templateSelection: function (data) {
			if (!data.id) return data.text;

			const $selection = $('<span>').text(data.text);

			const customClass = $(data.element).attr('class');
			if (customClass) {
				$selection.addClass(customClass);
			}

			return $selection;
		}
	});
});

$(function () {
	$('[data-bs-toggle="tooltip"]').tooltip();
});



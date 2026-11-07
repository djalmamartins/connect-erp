$(function () {
    // mobile menu open
    $(".j_menu_mobile_open").click(function (e) {
        e.preventDefault();

        $(".j_menu_mobile_tab").css("left", "auto").fadeIn(1).animate({"right": "0"}, 200);
    });

    // mobile menu close
    $(".j_menu_mobile_close").click(function (e) {
        e.preventDefault();

        $(".j_menu_mobile_tab").animate({"left": "100%"}, 200, function () {
            $(".j_menu_mobile_tab").css({
                "right": "auto",
                "display": "none"
            });
        });
    });

    // scroll animate
    $("[data-go]").click(function (e) {
        e.preventDefault();

        var goto = $($(this).data("go")).offset().top;
        $("html, body").animate({scrollTop: goto}, goto / 2, "easeOutBounce");
    });

    // modal open
    $("[data-modal]").click(function (e) {
        e.preventDefault();

        var modal = $(this).data("modal");
        $(modal).fadeIn(200).css("display", "flex");
    });

    // modal close
    $(".j_modal_close").click(function (e) {
        e.preventDefault();

        if ($(e.target).hasClass("j_modal_close")) {
            $(".j_modal_close").fadeOut(200);
        }

        var iframe = $(this).find("iframe");
        if (iframe) {
            iframe.attr("src", iframe.attr("src"));
        }
    });

    // MAKS
    $(".mask-date").mask('00/00/0000');
    $(".mask-datetime").mask('00/00/0000 00:00');
    $(".mask-month").mask('00/0000', {reverse: true});
    $(".mask-doc").mask('000.000.000-00', {reverse: true});
    $(".mask-pj").mask('00.000.000/0000-00', {reverse: true});
    $(".mask-card").mask('0000  0000  0000  0000', {reverse: true});
    $(".mask-money").mask('000.000.000.000.000,00', {reverse: true, placeholder: "0,00"});
    $(".mask-cep").mask('00000-000', {reverse: true});
    $(".mask-phone").mask('00 0000-0000', {reverse: true});
    $(".mask-cell").mask('00 00000-0000', {reverse: true});

    // Email || CPF - MAKS
    $('#cpf-email').on('input', function() {
        let input = $(this);
        let cpfEmail = input.val();
        let pjCpf = cpfEmail.length;
        if(!cpfEmail.replace(/[^\d]/gi, '')) {
            // não é um número
            $("#cpf-email").unmask();
        } else {
            if(pjCpf < 14){
                $("#cpf-email").mask('000.000.000-00', {reverse: true});
            }else{
                $("#cpf-email").mask('00.000.000/0000-00', {reverse: true});
            }
        }
    });

    // collpase
    $(".j_collapse").click(function () {
        var collapse = $(this);

        collapse.parents().find(".j_collapse_icon").removeClass("icon-minus").addClass("icon-plus");
        collapse.find(".j_collapse_icon").removeClass("icon-plus").addClass("icon-minus");

        if (collapse.find(".j_collapse_box").is(":visible")) {
            collapse.find(".j_collapse_box").slideUp(200);
        } else {
            collapse.parent().find(".j_collapse_box").slideUp(200);
            collapse.find(".j_collapse_box").slideDown(200);
        }
    });


    //ajax form
    $("form:not('.ajax_off')").submit(function (e) {
        e.preventDefault();
        var form = $(this);
        var load = $(".ajax_load");
        var flashClass = "ajax_response";
        var flash = $("." + flashClass);

        form.ajaxSubmit({
            url: form.attr("action"),
            type: "POST",
            dataType: "json",
            beforeSend: function () {
                load.fadeIn(200).css("display", "flex");
            },
            success: function (response) {
                //redirect
                if (response.redirect) {
                    window.location.href = response.redirect;
                } else {
                    load.fadeOut(200);
                }

                //message
                if (response.message) {
                    if (flash.length) {
                        flash.html(response.message).fadeIn(100).effect("bounce", 300);
                    } else {
                        form.prepend("<div class='" + flashClass + "'>" + response.message + "</div>")
                            .find("." + flashClass).effect("bounce", 300);
                    }
                } else {
                    flash.fadeOut(100);
                }
            },
            complete: function () {
                if (form.data("reset") === true) {
                    form.trigger("reset");
                }
            }
        });
    });

    //top bar
    $(window).on("scroll", function () {
        if ($(window).scrollTop() > 50) {
            $(".main_header").addClass("main_header_fixed");
        } else {
            $(".main_header").removeClass("main_header_fixed");
        }
    });

    // Click event of the showPassword button
    $('.view-password').on('click', function (e) {
        e.preventDefault();

        var passwordField = $('#password');
        var passrewordField = $('#password_re');
        var passwordFieldType = passwordField.attr('type');
        var passrewordFieldType = passrewordField.attr('type');


        if (passwordFieldType == 'password' || passrewordFieldType == 'password_re') {
            passwordField.attr('type', 'text');
            passrewordField.attr('type', 'text');
            $(this).attr("class", "view-password icon-notext icon-unlock-alt");
        } else {
            passwordField.attr('type', 'password');
            passrewordField.attr('type', 'password');
            $(this).attr("class", "view-password icon-notext icon-lock");
        }
    });
});

$(document).ready(function () {

    $('a[href^="#"]').on('click', function (e) {

        e.preventDefault();

        const target = $(this).attr('href');

        const headerHeight = 90;

        $('html, body').animate({
            scrollTop: $(target).offset().top - headerHeight
        }, 800);

    });

});